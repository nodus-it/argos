<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\IntegrationProvider;
use App\Filament\Admin\Pages\Onboarding;
use App\Models\AgentCredential;
use App\Models\ProviderCredential;
use App\Models\ProviderOAuthConfig;
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
        // The backfill migration may have created credentials from the developer's
        // real ~/.config/argos/claude_token file. Remove them so tests start clean.
        AgentCredential::query()->where('agent_name', AgentName::ClaudeCode->value)->delete();
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

        // With an env token the agent step is already satisfied, so the wizard
        // resumes on step 2; step back to see the Claude env hint.
        Livewire::test(Onboarding::class)
            ->assertSet('tokenSource', 'env')
            ->assertSet('currentStep', 2)
            ->call('goToStep', 1)
            ->assertSee('CLAUDE_CODE_OAUTH_TOKEN');
    }

    public function test_save_claude_token_persists_and_updates_state(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);

        Livewire::test(Onboarding::class)
            ->set('claudeToken', 'sk-ant-oat01-fake')
            ->call('saveClaudeToken')
            ->assertSet('tokenSource', 'agent_credential')
            ->assertSet('claudeToken', '');

        $cred = AgentCredential::query()
            ->where('agent_name', AgentName::ClaudeCode->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->first();
        $this->assertNotNull($cred);
        $this->assertSame('sk-ant-oat01-fake', $cred->credentials['token']);
    }

    public function test_save_claude_token_rejects_invalid_token(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('', 401)]);

        Livewire::test(Onboarding::class)
            ->set('claudeToken', 'sk-ant-bad')
            ->call('saveClaudeToken')
            ->assertSet('tokenSource', 'none');

        $this->assertFalse(
            AgentCredential::query()
                ->where('agent_name', AgentName::ClaudeCode->value)
                ->exists(),
        );
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
        $this->configureCodexAgent();
        config(['services.github.client_id' => null, 'services.github.client_secret' => null]);

        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 2)
            ->assertDontSee('Connect with GitHub');

        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with GitHub');
    }

    public function test_gitlab_connect_button_visible_when_gitlab_oauth_configured(): void
    {
        $this->configureCodexAgent();
        config([
            'services.gitlab.client_id' => 'gl-cid',
            'services.gitlab.client_secret' => 'gl-cs',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with GitLab');
    }

    public function test_bitbucket_connect_button_visible_when_bitbucket_oauth_configured(): void
    {
        $this->configureCodexAgent();
        config([
            'services.bitbucket.client_id' => 'bb-cid',
            'services.bitbucket.client_secret' => 'bb-cs',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with Bitbucket');
    }

    /** Create a Codex credential so the wizard's agent gate is satisfied. */
    private function configureCodexAgent(): void
    {
        AgentCredential::create([
            'agent_name' => AgentName::Codex->value,
            'name' => 'Default',
            'credentials' => ['tokens' => ['access_token' => 'sk-codex-test']],
            'status' => AgentCredentialStatus::Active->value,
        ]);
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

    public function test_onboarding_offers_self_hosted_gitlab_oauth(): void
    {
        $this->configureCodexAgent();
        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::GitLab,
            'instance_url' => 'https://git.example.com',
            'enabled' => true,
        ]);

        // Regression: a self-hosted GitLab OAuth app (instance_url set) is not in
        // config('services.*'), so the old config-only check hid it. It must now
        // surface with an instance-scoped connect link.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 2)
            ->assertSee('git.example.com')
            ->assertSee('instance=');
    }

    public function test_step_navigation_is_gated_by_agent_then_advances(): void
    {
        // No agent yet → next is refused and we stay on step 1.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 1)
            ->call('nextStep')
            ->assertSet('currentStep', 1);

        $this->configureCodexAgent();

        Livewire::test(Onboarding::class)
            ->call('goToStep', 1)
            ->assertSet('currentStep', 1)
            ->call('nextStep')
            ->assertSet('currentStep', 2);
    }

    public function test_create_project_not_in_header_actions(): void
    {
        Livewire::test(Onboarding::class)
            ->assertActionDoesNotExist('createProject');
    }

    public function test_step2_renders_with_credential_setup_links_when_no_oauth_configured(): void
    {
        $this->configureCodexAgent();
        config([
            'services.github.client_id' => null,
            'services.gitlab.client_id' => null,
            'services.bitbucket.client_id' => null,
        ]);

        // Regression: the deep links must resolve — the OAuth-config resource
        // slug is pinned, otherwise route() throws RouteNotFoundException here.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 2)
            ->assertSuccessful()
            ->assertSee(route('filament.admin.resources.provider-oauth-configs.create'))
            ->assertSee(route('filament.admin.resources.provider-credentials.create'));
    }

    public function test_step2_offers_adding_more_oauth_apps_when_one_is_configured(): void
    {
        $this->configureCodexAgent();
        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        // Even with GitHub configured, the "add another OAuth app" route must be
        // reachable so further providers / self-hosted instances can be added.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 2)
            ->assertSee('Connect with GitHub')
            ->assertSee(route('filament.admin.resources.provider-oauth-configs.create'));
    }

    public function test_inline_create_with_oauth_account_creates_repo_profile(): void
    {
        $this->configureCodexAgent();

        /** @var User $user */
        $user = auth()->user();
        $account = $user->connectedAccounts()->create([
            'provider' => 'github',
            'provider_id' => '42',
            'token' => 'gho_test',
            'nickname' => 'tester',
            'instance_url' => '',
        ]);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'acme/widget', 'default_branch' => 'main'],
            ]),
            'api.github.com/repos/acme/widget/branches*' => Http::response([
                ['name' => 'main'], ['name' => 'dev'],
            ]),
            'api.github.com/repos/acme/widget' => Http::response(['default_branch' => 'main']),
        ]);

        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 2)
            ->set('repoSource', "oauth:{$account->id}")
            ->set('selectedRepo', 'acme/widget')
            ->set('selectedBranch', 'main')
            ->set('projectName', 'widget')
            ->call('createProject')
            ->assertSet('currentStep', 3);

        $this->assertDatabaseHas(RepoProfile::class, [
            'name' => 'widget',
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'default_branch' => 'main',
            'url' => 'https://github.com/acme/widget',
        ]);
    }

    public function test_inline_create_with_pat_credential_creates_repo_profile(): void
    {
        $this->configureCodexAgent();

        $credential = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'token' => 'ghp-secret',
            'instance_url' => null,
        ]);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'acme/gadget', 'default_branch' => 'main'],
            ]),
            'api.github.com/repos/acme/gadget/branches*' => Http::response([['name' => 'main']]),
            'api.github.com/repos/acme/gadget' => Http::response(['default_branch' => 'main']),
        ]);

        Livewire::test(Onboarding::class)
            ->set('repoSource', "pat:{$credential->id}")
            ->set('selectedRepo', 'acme/gadget')
            ->set('selectedBranch', 'main')
            ->set('projectName', 'gadget')
            ->call('createProject')
            ->assertSet('currentStep', 3);

        $profile = RepoProfile::query()->where('name', 'gadget')->first();
        $this->assertNotNull($profile);
        $this->assertSame('pat', $profile->auth_method->value);
        $this->assertNull($profile->connected_account_id);
        $this->assertSame('ghp-secret', $profile->token);
    }

    public function test_inline_create_rejects_duplicate_name(): void
    {
        $this->configureCodexAgent();
        RepoProfile::factory()->create(['name' => 'widget']);

        $credential = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
        ]);

        Http::fake(['api.github.com/*' => Http::response([])]);

        Livewire::test(Onboarding::class)
            ->set('repoSource', "pat:{$credential->id}")
            ->set('selectedRepo', 'acme/widget')
            ->set('selectedBranch', 'main')
            ->set('projectName', 'widget')
            ->call('createProject')
            ->assertSet('currentStep', 2);

        $this->assertSame(1, RepoProfile::query()->where('name', 'widget')->count());
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

    public function test_onboarding_shows_codex_setup_box_when_no_codex_credential(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSet('codexConfigured', false)
            ->assertSee('OpenAI Codex')
            ->assertSee('codex login')
            ->assertSeeHtml('wire:click="saveCodexAuthJson"');
    }

    public function test_onboarding_marks_agents_done_when_only_codex_credential_present(): void
    {
        AgentCredential::create([
            'agent_name' => AgentName::Codex->value,
            'name' => 'Default',
            'credentials' => ['tokens' => ['access_token' => 'sk-codex-test']],
            'status' => AgentCredentialStatus::Active->value,
        ]);

        // With one agent configured, the gate is satisfied and the wizard
        // resumes on the repository step.
        Livewire::test(Onboarding::class)
            ->assertSet('codexConfigured', true)
            ->assertSet('tokenSource', 'none')
            ->assertSet('currentStep', 2);
    }

    public function test_save_codex_auth_json_persists_and_clears_input(): void
    {
        $authJson = json_encode([
            'OPENAI_API_KEY' => null,
            'tokens' => ['access_token' => 'sk-codex-test', 'id_token' => 'id-test'],
        ]);

        Livewire::test(Onboarding::class)
            ->set('codexAuthJson', $authJson)
            ->call('saveCodexAuthJson')
            ->assertSet('codexConfigured', true)
            ->assertSet('codexAuthJson', '');

        $cred = AgentCredential::query()
            ->where('agent_name', AgentName::Codex->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->first();
        $this->assertNotNull($cred);
        $this->assertSame('sk-codex-test', $cred->credentials['tokens']['access_token']);
    }

    public function test_save_codex_auth_json_rejects_invalid_json(): void
    {
        Livewire::test(Onboarding::class)
            ->set('codexAuthJson', 'not-json {')
            ->call('saveCodexAuthJson')
            ->assertSet('codexConfigured', false);

        $this->assertFalse(
            AgentCredential::query()
                ->where('agent_name', AgentName::Codex->value)
                ->exists(),
        );
    }

    public function test_save_codex_auth_json_rejects_empty_input(): void
    {
        Livewire::test(Onboarding::class)
            ->set('codexAuthJson', '   ')
            ->call('saveCodexAuthJson')
            ->assertSet('codexConfigured', false);
    }
}
