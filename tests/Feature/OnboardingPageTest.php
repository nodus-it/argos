<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\Onboarding;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\CredentialStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        config(['argos.config_dir' => storage_path('framework/testing/argos-'.uniqid())]);
        config(['argos.claude_token' => null]);
    }

    public function test_onboarding_page_renders(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSuccessful()
            ->assertSee('Set up Argos');
    }

    public function test_onboarding_shows_token_help_when_missing(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSee('claude setup-token');
    }

    public function test_onboarding_marks_token_done_when_env_set(): void
    {
        config(['argos.claude_token' => 'sk-ant-test']);

        Livewire::test(Onboarding::class)
            ->assertSet('tokenSource', 'env')
            ->assertSee('CLAUDE_CODE_OAUTH_TOKEN');
    }

    public function test_save_claude_token_persists_and_updates_state(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);

        Livewire::test(Onboarding::class)
            ->set('claudeToken', 'sk-ant-oat01-fake')
            ->call('saveClaudeToken')
            ->assertSet('tokenSource', 'file')
            ->assertSet('claudeToken', '');

        $this->assertSame('sk-ant-oat01-fake', app(CredentialStore::class)->getClaudeToken());
    }

    public function test_save_claude_token_rejects_invalid_token(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('', 401)]);

        Livewire::test(Onboarding::class)
            ->set('claudeToken', 'sk-ant-bad')
            ->call('saveClaudeToken')
            ->assertSet('tokenSource', 'none');

        $this->assertNull(app(CredentialStore::class)->getClaudeToken());
    }

    public function test_save_claude_token_rejects_empty_input(): void
    {
        Livewire::test(Onboarding::class)
            ->set('claudeToken', '   ')
            ->call('saveClaudeToken')
            ->assertSet('tokenSource', 'none');
    }

    public function test_github_connect_button_only_visible_when_oauth_configured(): void
    {
        config(['services.github.client_id' => null, 'services.github.client_secret' => null]);

        Livewire::test(Onboarding::class)
            ->assertDontSee('Connect with GitHub');

        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with GitHub');
    }

    public function test_gitlab_connect_button_visible_when_gitlab_oauth_configured(): void
    {
        config([
            'services.gitlab.client_id' => 'gl-cid',
            'services.gitlab.client_secret' => 'gl-cs',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with GitLab');
    }

    public function test_bitbucket_connect_button_visible_when_bitbucket_oauth_configured(): void
    {
        config([
            'services.bitbucket.client_id' => 'bb-cid',
            'services.bitbucket.client_secret' => 'bb-cs',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with Bitbucket');
    }

    public function test_disconnect_provider_removes_connected_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->connectedAccounts()->create([
            'provider' => 'github',
            'provider_id' => '12345',
            'token' => 'gho_test',
            'nickname' => 'tester',
        ]);

        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        Livewire::test(Onboarding::class)
            ->call('disconnectProvider', 'github');

        $this->assertSame(0, $user->connectedAccounts()->where('provider', 'github')->count());
    }

    public function test_create_project_button_links_to_repo_profile_create(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSee(route('filament.admin.resources.repo-profiles.create'));
    }

    public function test_create_project_not_in_header_actions(): void
    {
        Livewire::test(Onboarding::class)
            ->assertActionDoesNotExist('createProject');
    }

    public function test_onboarding_hidden_from_nav_when_project_exists(): void
    {
        RepoProfile::factory()->create();

        $this->assertFalse(Onboarding::shouldRegisterNavigation());
    }

    public function test_onboarding_visible_in_nav_when_no_project(): void
    {
        $this->assertTrue(Onboarding::shouldRegisterNavigation());
    }
}
