<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RepoProfileResolveTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_pat_token_is_returned_unchanged(): void
    {
        $profile = RepoProfile::factory()->create([
            'auth_method' => 'pat',
            'token' => 'ghp_pat_value',
        ]);

        $this->assertSame('ghp_pat_value', $profile->resolveToken());
    }

    public function test_pat_without_token_throws(): void
    {
        $profile = RepoProfile::factory()->create([
            'auth_method' => 'pat',
            'token' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $profile->resolveToken();
    }

    public function test_oauth_token_with_no_expiry_is_returned_without_refresh(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'token' => 'gho_long_lived',
            'refresh_token' => null,
            'expires_at' => null,
        ]);
        $profile = RepoProfile::factory()->create([
            'auth_method' => 'oauth',
            'platform' => 'github',
            'connected_account_id' => $account->id,
        ]);

        $this->assertSame('gho_long_lived', $profile->resolveToken());
        Http::assertNothingSent();
    }

    public function test_oauth_token_close_to_expiry_is_refreshed(): void
    {
        config()->set('services.bitbucket.client_id', 'bb-id');
        config()->set('services.bitbucket.client_secret', 'bb-secret');

        Http::fake([
            'https://bitbucket.org/site/oauth2/access_token' => Http::response([
                'access_token' => 'bb-fresh',
                'refresh_token' => 'bb-rotated',
                'expires_in' => 7200,
            ]),
        ]);

        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'bitbucket',
            'token' => 'bb-stale',
            'refresh_token' => 'bb-old',
            'expires_at' => now()->addMinutes(20),
        ]);
        $profile = RepoProfile::factory()->create([
            'auth_method' => 'oauth',
            'platform' => 'bitbucket',
            'connected_account_id' => $account->id,
            'url' => 'https://bitbucket.org/ws/repo',
        ]);

        $this->assertSame('bb-fresh', $profile->resolveToken());
        $this->assertSame('bb-fresh', $account->fresh()->token);
        $this->assertSame('bb-rotated', $account->fresh()->refresh_token);
    }

    public function test_oauth_with_no_linked_account_throws(): void
    {
        $profile = RepoProfile::factory()->create([
            'auth_method' => 'oauth',
            'platform' => 'github',
            'connected_account_id' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $profile->resolveToken();
    }
}
