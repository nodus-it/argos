<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class ConnectedAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_redirect_requires_auth(): void
    {
        auth()->logout();

        // Bypass actingAs by making a fresh anonymous request via a new test call
        $response = $this->withSession([])->get(route('auth.github.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringNotContainsString('github.com', $location);
    }

    public function test_redirect_redirects_to_github(): void
    {
        $response = $this->get(route('auth.github.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('github.com', $response->headers->get('Location') ?? '');
    }

    public function test_callback_stores_connected_account(): void
    {
        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect();

        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'github',
            'provider_id' => '12345678',
            'nickname' => 'testuser',
        ]);
    }

    public function test_callback_updates_existing_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
            'provider_id' => '12345678',
            'nickname' => 'old-name',
        ]);

        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $this->get(route('auth.github.callback'));

        $this->assertDatabaseCount(ConnectedAccount::class, 1);
        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'github',
            'nickname' => 'testuser',
        ]);
    }

    public function test_callback_redirects_to_connected_accounts_page(): void
    {
        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
    }

    public function test_redirect_with_onboarding_return_sets_session_marker(): void
    {
        $this->get(route('auth.github.redirect', ['return' => 'onboarding']));

        $this->assertSame('onboarding', session('oauth.github.return'));
    }

    public function test_redirect_without_return_clears_session_marker(): void
    {
        session(['oauth.github.return' => 'onboarding']);

        $this->get(route('auth.github.redirect'));

        $this->assertNull(session('oauth.github.return'));
    }

    public function test_callback_redirects_to_onboarding_when_marker_set(): void
    {
        session(['oauth.github.return' => 'onboarding']);

        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
        $this->assertNull(session('oauth.github.return'));
    }

    public function test_disconnect_removes_connected_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        $response = $this->post(route('auth.github.disconnect'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);
    }

    public function test_callback_relinks_orphaned_profiles_after_reconnect(): void
    {
        // Vorher verbunden, dann disconnect → Profil zeigt auf NULL
        $orphan = RepoProfile::factory()->create([
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => null,
            'url' => 'https://github.com/nodus-it/repo',
        ]);

        $this->mockSocialiteCallback($this->makeSocialiteUser());

        $this->get(route('auth.github.callback'));

        $newAccount = ConnectedAccount::where('user_id', $this->user->id)
            ->where('provider', 'github')
            ->firstOrFail();
        $this->assertSame($newAccount->id, $orphan->fresh()->connected_account_id);
    }

    public function test_disconnect_is_no_op_when_not_connected(): void
    {
        $response = $this->post(route('auth.github.disconnect'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
        $this->assertDatabaseCount(ConnectedAccount::class, 0);
    }

    private function makeSocialiteUser(): SocialiteUser
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = '12345678';
        $socialiteUser->token = 'gho_testtoken';
        $socialiteUser->refreshToken = null;
        $socialiteUser->expiresIn = null;
        $socialiteUser->name = 'Test User';
        $socialiteUser->nickname = 'testuser';
        $socialiteUser->avatar = 'https://avatars.githubusercontent.com/u/12345678';

        return $socialiteUser;
    }

    private function mockSocialiteCallback(SocialiteUser $socialiteUser): void
    {
        $provider = Mockery::mock(GithubProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        $socialite = Mockery::mock(SocialiteFactory::class);
        $socialite->shouldReceive('driver')->with('github')->andReturn($provider);

        $this->app->instance(SocialiteFactory::class, $socialite);
    }
}
