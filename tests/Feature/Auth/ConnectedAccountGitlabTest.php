<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class ConnectedAccountGitlabTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        config([
            'services.gitlab.client_id' => 'test-client-id',
            'services.gitlab.client_secret' => 'test-client-secret',
            'services.gitlab.redirect' => '/auth/gitlab/callback',
            'services.gitlab.instance_uri' => 'https://gitlab.com',
        ]);
    }

    public function test_redirect_requires_auth(): void
    {
        auth()->logout();

        $response = $this->withSession([])->get(route('auth.gitlab.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringNotContainsString('gitlab.com/oauth', $location);
    }

    public function test_redirect_redirects_to_gitlab(): void
    {
        $response = $this->get(route('auth.gitlab.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('gitlab.com', $response->headers->get('Location') ?? '');
    }

    public function test_callback_stores_connected_account(): void
    {
        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $this->get(route('auth.gitlab.callback'));

        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
            'provider_id' => '99887766',
            'nickname' => 'gitlabuser',
        ]);
    }

    public function test_callback_stores_null_instance_url_for_gitlab_com(): void
    {
        config(['services.gitlab.instance_uri' => 'https://gitlab.com']);

        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $this->get(route('auth.gitlab.callback'));

        $account = ConnectedAccount::where('user_id', $this->user->id)
            ->where('provider', 'gitlab')
            ->first();

        $this->assertNotNull($account);
        $this->assertNull($account->instance_url);
    }

    public function test_callback_stores_instance_url_for_self_hosted(): void
    {
        config(['services.gitlab.instance_uri' => 'https://git.example.com']);

        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $this->get(route('auth.gitlab.callback'));

        $account = ConnectedAccount::where('user_id', $this->user->id)
            ->where('provider', 'gitlab')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('https://git.example.com', $account->instance_url);
    }

    public function test_callback_updates_existing_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
            'provider_id' => '99887766',
            'nickname' => 'old-name',
        ]);

        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $this->get(route('auth.gitlab.callback'));

        $this->assertDatabaseCount(ConnectedAccount::class, 1);
        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
            'nickname' => 'gitlabuser',
        ]);
    }

    public function test_callback_redirects_to_connected_accounts_page(): void
    {
        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get(route('auth.gitlab.callback'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
    }

    public function test_callback_redirects_to_onboarding_when_marker_set(): void
    {
        session(['oauth.gitlab.return' => 'onboarding']);

        $socialiteUser = $this->makeSocialiteUser();
        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get(route('auth.gitlab.callback'));

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
        $this->assertNull(session('oauth.gitlab.return'));
    }

    public function test_disconnect_removes_connected_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
        ]);

        $response = $this->post(route('auth.gitlab.disconnect'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
        $this->assertDatabaseMissing(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
        ]);
    }

    public function test_disconnect_does_not_remove_github_account(): void
    {
        ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        $this->post(route('auth.gitlab.disconnect'));

        $this->assertDatabaseHas(ConnectedAccount::class, [
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);
    }

    public function test_disconnect_is_no_op_when_not_connected(): void
    {
        $response = $this->post(route('auth.gitlab.disconnect'));

        $response->assertRedirect(route('filament.admin.pages.connected-accounts'));
        $this->assertDatabaseCount(ConnectedAccount::class, 0);
    }

    public function test_redirect_with_onboarding_return_sets_session_marker(): void
    {
        $this->get(route('auth.gitlab.redirect', ['return' => 'onboarding']));

        $this->assertSame('onboarding', session('oauth.gitlab.return'));
    }

    private function makeSocialiteUser(): SocialiteUser
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = '99887766';
        $socialiteUser->token = 'gloo_testtoken';
        $socialiteUser->refreshToken = null;
        $socialiteUser->expiresIn = null;
        $socialiteUser->name = 'GitLab User';
        $socialiteUser->nickname = 'gitlabuser';
        $socialiteUser->avatar = 'https://gitlab.com/uploads/-/system/user/avatar/1/avatar.png';

        return $socialiteUser;
    }

    private function mockSocialiteCallback(SocialiteUser $socialiteUser): void
    {
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        $socialite = Mockery::mock(SocialiteFactory::class);
        $socialite->shouldReceive('driver')->with('gitlab')->andReturn($provider);

        $this->app->instance(SocialiteFactory::class, $socialite);
    }
}
