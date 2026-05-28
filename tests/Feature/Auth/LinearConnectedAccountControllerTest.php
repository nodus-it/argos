<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinearConnectedAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.linear.client_id' => 'lin_client_id_test',
            'services.linear.client_secret' => 'lin_client_secret_test',
            'services.linear.redirect' => '/auth/linear/callback',
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ── redirect ─────────────────────────────────────────────────────────────

    public function test_redirect_requires_auth(): void
    {
        auth()->logout();

        $response = $this->withSession([])->get(route('auth.linear.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringNotContainsString('linear.app', $location);
    }

    public function test_redirect_redirects_to_linear_authorize(): void
    {
        $response = $this->get(route('auth.linear.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('linear.app/oauth/authorize', $response->headers->get('Location') ?? '');
    }

    public function test_redirect_includes_client_id_in_url(): void
    {
        $response = $this->get(route('auth.linear.redirect'));

        $location = $response->headers->get('Location') ?? '';
        $this->assertStringContainsString('client_id=lin_client_id_test', $location);
    }

    public function test_redirect_stores_state_in_session(): void
    {
        $this->get(route('auth.linear.redirect'));

        $this->assertNotNull(session('oauth.linear.state'));
    }

    public function test_redirect_with_onboarding_return_sets_session_marker(): void
    {
        $this->get(route('auth.linear.redirect', ['return' => 'onboarding']));

        $this->assertSame('onboarding', session('oauth.linear.return'));
    }

    public function test_redirect_without_return_clears_session_marker(): void
    {
        session(['oauth.linear.return' => 'onboarding']);

        $this->get(route('auth.linear.redirect'));

        $this->assertNull(session('oauth.linear.return'));
    }

    // ── callback ─────────────────────────────────────────────────────────────

    public function test_callback_stores_connected_account(): void
    {
        $this->fakeLinearOAuth();

        $state = 'test-state-value';
        session(['oauth.linear.state' => $state]);

        $response = $this->get(route('auth.linear.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]));

        $response->assertRedirect();

        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'linear',
            'provider_id' => 'linear-viewer-uuid',
            'nickname' => 'test@example.com',
        ]);
    }

    public function test_callback_updates_existing_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'linear',
            'provider_id' => 'linear-viewer-uuid',
            'nickname' => 'old@example.com',
        ]);

        $this->fakeLinearOAuth();

        $state = 'test-state-value';
        session(['oauth.linear.state' => $state]);

        $this->get(route('auth.linear.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]));

        $this->assertDatabaseCount(ConnectedAccount::class, 1);
        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'linear',
            'nickname' => 'test@example.com',
        ]);
    }

    public function test_callback_redirects_to_connected_accounts_page(): void
    {
        $this->fakeLinearOAuth();

        $state = 'test-state-value';
        session(['oauth.linear.state' => $state]);

        $response = $this->get(route('auth.linear.callback', [
            'code' => 'auth-code-123',
            'state' => $state,
        ]));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
    }

    public function test_callback_redirects_to_onboarding_when_marker_set(): void
    {
        $this->fakeLinearOAuth();

        session([
            'oauth.linear.state' => 'test-state-value',
            'oauth.linear.return' => 'onboarding',
        ]);

        $response = $this->get(route('auth.linear.callback', [
            'code' => 'auth-code-123',
            'state' => 'test-state-value',
        ]));

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
        $this->assertNull(session('oauth.linear.return'));
    }

    public function test_callback_rejects_invalid_state(): void
    {
        session(['oauth.linear.state' => 'correct-state']);

        $response = $this->get(route('auth.linear.callback', [
            'code' => 'auth-code-123',
            'state' => 'wrong-state',
        ]));

        $response->assertStatus(403);
    }

    public function test_callback_rejects_missing_state(): void
    {
        $response = $this->get(route('auth.linear.callback', ['code' => 'auth-code-123']));

        $response->assertStatus(403);
    }

    // ── disconnect ───────────────────────────────────────────────────────────

    public function test_disconnect_removes_connected_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'linear',
        ]);

        $response = $this->post(route('auth.linear.disconnect'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'linear',
        ]);
    }

    public function test_disconnect_is_no_op_when_not_connected(): void
    {
        $response = $this->post(route('auth.linear.disconnect'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
        $this->assertDatabaseCount(ConnectedAccount::class, 0);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function fakeLinearOAuth(): void
    {
        Http::fake([
            'https://api.linear.app/oauth/token' => Http::response([
                'access_token' => 'lin_oauth_access_token',
                'token_type' => 'Bearer',
                'scope' => 'read write',
            ]),
            'https://api.linear.app/graphql' => Http::response([
                'data' => [
                    'viewer' => [
                        'id' => 'linear-viewer-uuid',
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                        'avatarUrl' => 'https://linear.app/avatars/test.jpg',
                    ],
                ],
            ]),
        ]);
    }
}
