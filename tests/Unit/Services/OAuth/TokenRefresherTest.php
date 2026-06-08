<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OAuth;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\OAuth\TokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class TokenRefresherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.github.client_id', 'gh-client-id');
        config()->set('services.github.client_secret', 'gh-client-secret');
        config()->set('services.gitlab.client_id', 'gl-client-id');
        config()->set('services.gitlab.client_secret', 'gl-client-secret');
        config()->set('services.bitbucket.client_id', 'bb-client-id');
        config()->set('services.bitbucket.client_secret', 'bb-client-secret');
    }

    public function test_needs_refresh_returns_false_for_null_expires_at(): void
    {
        $account = $this->makeAccount('github', expiresAt: null);

        $this->assertFalse(app(TokenRefresher::class)->needsRefresh($account));
    }

    public function test_needs_refresh_returns_false_for_token_far_in_future(): void
    {
        $account = $this->makeAccount(
            'github',
            expiresAt: now()->addHours(8),
        );

        $this->assertFalse(app(TokenRefresher::class)->needsRefresh($account));
    }

    public function test_needs_refresh_returns_true_when_within_buffer(): void
    {
        $account = $this->makeAccount(
            'github',
            expiresAt: now()->addMinutes(30),
        );

        $this->assertTrue(app(TokenRefresher::class)->needsRefresh($account));
    }

    public function test_refresh_if_needed_is_a_noop_when_token_is_fresh(): void
    {
        Saloon::fake([]);
        $account = $this->makeAccount(
            'bitbucket',
            token: 'old-token',
            expiresAt: now()->addHours(8),
        );

        $result = app(TokenRefresher::class)->refreshIfNeeded($account);

        $this->assertSame('old-token', $result->token);
        Saloon::assertNothingSent();
    }

    public function test_refresh_if_needed_throws_when_no_refresh_token_present(): void
    {
        $account = $this->makeAccount(
            'github',
            refreshToken: null,
            expiresAt: now()->addMinutes(5),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refresh_token/');

        app(TokenRefresher::class)->refreshIfNeeded($account);
    }

    public function test_refresh_calls_github_endpoint_and_persists_new_tokens(): void
    {
        Saloon::fake([
            'https://github.com/login/oauth/access_token' => MockResponse::make([
                'access_token' => 'gh-new-access',
                'refresh_token' => 'gh-new-refresh',
                'expires_in' => 28800,
                'token_type' => 'Bearer',
            ]),
        ]);

        $account = $this->makeAccount(
            'github',
            token: 'gh-old-access',
            refreshToken: 'gh-old-refresh',
            expiresAt: now()->addMinutes(15),
        );

        $result = app(TokenRefresher::class)->refreshIfNeeded($account);

        $this->assertSame('gh-new-access', $result->token);
        $this->assertSame('gh-new-refresh', $result->refresh_token);
        $this->assertNotNull($result->expires_at);
        $this->assertGreaterThan(now()->addHours(7), $result->expires_at);

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request->body()->all();

            $this->assertSame('https://github.com/login/oauth/access_token', $response->getPendingRequest()->getUrl());
            $this->assertSame('refresh_token', $body['grant_type']);
            $this->assertSame('gh-old-refresh', $body['refresh_token']);
            $this->assertSame('gh-client-id', $body['client_id']);
            $this->assertSame('gh-client-secret', $body['client_secret']);

            return true;
        });

        $this->assertDatabaseHas('connected_accounts', ['id' => $account->id]);
        $this->assertSame('gh-new-access', $account->fresh()->token);
    }

    public function test_refresh_calls_bitbucket_endpoint(): void
    {
        Saloon::fake([
            'https://bitbucket.org/site/oauth2/access_token' => MockResponse::make([
                'access_token' => 'bb-new-access',
                'refresh_token' => 'bb-rotated-refresh',
                'expires_in' => 7200,
            ]),
        ]);

        $account = $this->makeAccount(
            'bitbucket',
            refreshToken: 'bb-old-refresh',
            expiresAt: now()->addMinutes(20),
        );

        app(TokenRefresher::class)->refreshIfNeeded($account);

        Saloon::assertSent(fn (Request $r, $response): bool => $response->getPendingRequest()->getUrl() === 'https://bitbucket.org/site/oauth2/access_token'
            && $r->body()->all()['grant_type'] === 'refresh_token'
            && $r->body()->all()['client_id'] === 'bb-client-id'
        );
        $this->assertSame('bb-rotated-refresh', $account->fresh()->refresh_token);
    }

    public function test_refresh_uses_account_instance_url_for_self_hosted_gitlab(): void
    {
        Saloon::fake([
            'https://gitlab.example.com/oauth/token' => MockResponse::make([
                'access_token' => 'gl-new',
                'refresh_token' => 'gl-rotated',
                'expires_in' => 7200,
            ]),
        ]);

        $account = $this->makeAccount(
            'gitlab',
            refreshToken: 'gl-old',
            expiresAt: now()->addMinutes(10),
            instanceUrl: 'https://gitlab.example.com',
        );

        app(TokenRefresher::class)->refreshIfNeeded($account);

        Saloon::assertSent(
            fn (Request $r, $response): bool => $response->getPendingRequest()->getUrl() === 'https://gitlab.example.com/oauth/token',
        );
    }

    public function test_refresh_keeps_existing_refresh_token_when_provider_omits_it(): void
    {
        Saloon::fake([
            'https://github.com/login/oauth/access_token' => MockResponse::make([
                'access_token' => 'gh-rotated',
                'expires_in' => 28800,
            ]),
        ]);

        $account = $this->makeAccount(
            'github',
            refreshToken: 'gh-keep-me',
            expiresAt: now()->addMinutes(5),
        );

        app(TokenRefresher::class)->refreshIfNeeded($account);

        $this->assertSame('gh-keep-me', $account->fresh()->refresh_token);
    }

    public function test_refresh_throws_when_provider_returns_4xx(): void
    {
        Saloon::fake([
            'https://bitbucket.org/site/oauth2/access_token' => MockResponse::make([
                'error' => 'invalid_grant',
                'error_description' => 'refresh_token revoked',
            ], 401),
        ]);

        $account = $this->makeAccount(
            'bitbucket',
            refreshToken: 'revoked',
            expiresAt: now()->addMinutes(5),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');

        app(TokenRefresher::class)->refreshIfNeeded($account);
    }

    public function test_refresh_throws_when_response_lacks_access_token(): void
    {
        Saloon::fake([
            'https://github.com/login/oauth/access_token' => MockResponse::make([
                'note' => 'malformed but 200 OK',
            ]),
        ]);

        $account = $this->makeAccount(
            'github',
            refreshToken: 'gh-old',
            expiresAt: now()->addMinutes(5),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/access_token/');

        app(TokenRefresher::class)->refreshIfNeeded($account);
    }

    private function makeAccount(
        string $provider,
        ?string $token = 'old-access',
        ?string $refreshToken = 'old-refresh',
        ?Carbon $expiresAt = null,
        ?string $instanceUrl = null,
    ): ConnectedAccount {
        $user = User::factory()->create();

        return ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'token' => $token,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'instance_url' => $instanceUrl,
        ]);
    }
}
