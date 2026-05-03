<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Credentials\CredentialStore;
use App\Filament\Admin\Pages\Onboarding;
use App\Models\RepoProfile;
use App\Models\User;
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
            ->assertSee('Argos einrichten');
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
            ->assertSet('githubOAuthAvailable', false)
            ->assertDontSee('Mit GitHub verbinden');

        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        Livewire::test(Onboarding::class)
            ->assertSet('githubOAuthAvailable', true)
            ->assertSee('Mit GitHub verbinden');
    }

    public function test_disconnect_github_removes_connected_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->connectedAccounts()->create([
            'provider' => 'github',
            'provider_id' => '12345',
            'token' => 'gho_test',
            'nickname' => 'tester',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSet('githubConnected', true)
            ->call('disconnectGitHub')
            ->assertSet('githubConnected', false);

        $this->assertSame(0, $user->connectedAccounts()->where('provider', 'github')->count());
    }

    public function test_create_project_button_links_to_repo_profile_create(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSee(route('filament.admin.resources.repo-profiles.create'));
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
