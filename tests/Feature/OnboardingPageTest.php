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
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
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
        // The token help lives on the agents step (2); step 1 is security.
        Livewire::test(Onboarding::class)
            ->call('goToStep', 2)
            ->assertSee('claude setup-token');
    }

    public function test_steps_reflect_initial_state_without_agent(): void
    {
        $steps = Livewire::test(Onboarding::class)->instance()->steps();

        // Step 1 (security) is active and reachable; nothing is done yet.
        $this->assertSame(1, $steps[0]['number']);
        $this->assertTrue($steps[0]['active']);
        $this->assertTrue($steps[0]['reachable']);
        $this->assertFalse($steps[0]['done']);

        // The security step is optional, so agents (step 2) is reachable too.
        $this->assertTrue($steps[1]['reachable']);

        // Repository and done stay locked until an agent is configured.
        $this->assertFalse($steps[2]['reachable']);
        $this->assertFalse($steps[3]['reachable']);
    }

    public function test_steps_mark_earlier_steps_done_when_agent_configured(): void
    {
        AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'credentials' => ['token' => 'sk-configured'],
            'status' => AgentCredentialStatus::Active,
        ]);

        // mount() resumes on the repository step (3) once an agent exists.
        $steps = Livewire::test(Onboarding::class)->instance()->steps();

        // Security (1) and agents (2) are behind us and marked done.
        $this->assertTrue($steps[0]['done']);
        $this->assertTrue($steps[1]['done']);
        $this->assertFalse($steps[1]['active']);

        // Repository (3) is the active, reachable step; done (4) stays locked.
        $this->assertTrue($steps[2]['active']);
        $this->assertTrue($steps[2]['reachable']);
        $this->assertFalse($steps[3]['reachable']);
    }

    public function test_security_step_shows_default_password_warning(): void
    {
        config(['argos.admin_password' => 'secret-default']);
        $user = User::factory()->admin()->create(['password' => Hash::make('secret-default')]);
        $this->actingAs($user);

        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 1)
            ->assertSee('default password');
    }

    public function test_using_default_password_detects_seeded_default(): void
    {
        config(['argos.admin_password' => 'secret-default']);
        $user = User::factory()->admin()->create(['password' => Hash::make('secret-default')]);
        $this->actingAs($user);

        $this->assertTrue(Livewire::test(Onboarding::class)->instance()->usingDefaultPassword());
    }

    public function test_using_default_password_false_for_custom_password(): void
    {
        config(['argos.admin_password' => 'secret-default']);
        $user = User::factory()->admin()->create(['password' => Hash::make('my-own-password')]);
        $this->actingAs($user);

        $this->assertFalse(Livewire::test(Onboarding::class)->instance()->usingDefaultPassword());
    }

    public function test_save_password_updates_the_user(): void
    {
        config(['argos.admin_password' => 'secret-default']);
        $user = User::factory()->admin()->create(['password' => Hash::make('secret-default')]);
        $this->actingAs($user);

        Livewire::test(Onboarding::class)
            ->set('newPassword', 'a-brand-new-pw')
            ->set('newPasswordConfirmation', 'a-brand-new-pw')
            ->call('savePassword')
            ->assertSet('newPassword', '')
            ->assertSet('newPasswordConfirmation', '')
            ->assertSet('passwordJustChanged', true);

        $this->assertTrue(Hash::check('a-brand-new-pw', (string) $user->fresh()?->password));
    }

    public function test_save_password_rejects_mismatched_confirmation(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($user);

        Livewire::test(Onboarding::class)
            ->set('newPassword', 'a-brand-new-pw')
            ->set('newPasswordConfirmation', 'does-not-match')
            ->call('savePassword')
            ->assertSet('passwordJustChanged', false);

        $this->assertTrue(Hash::check('old-password', (string) $user->fresh()?->password));
    }

    public function test_save_password_rejects_too_short(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($user);

        Livewire::test(Onboarding::class)
            ->set('newPassword', 'short')
            ->set('newPasswordConfirmation', 'short')
            ->call('savePassword')
            ->assertSet('passwordJustChanged', false);

        $this->assertTrue(Hash::check('old-password', (string) $user->fresh()?->password));
    }

    public function test_save_claude_token_persists_and_updates_state(): void
    {
        Saloon::fake(['https://api.anthropic.com/v1/models' => MockResponse::make(['data' => []], 200)]);

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
        // The admin's own subscription — their first workspace's setup task runs on it.
        $this->assertSame(auth()->id(), $cred->user_id);
    }

    public function test_another_persons_agent_does_not_unlock_the_wizard(): void
    {
        AgentCredential::factory()->ownedBy(User::factory()->create())->create(['agent_name' => AgentName::ClaudeCode, 'status' => AgentCredentialStatus::Active]);

        Livewire::test(Onboarding::class)
            ->assertSet('tokenSource', 'none')
            ->assertSet('currentStep', 1);
    }

    public function test_save_claude_token_rejects_invalid_token(): void
    {
        Saloon::fake(['https://api.anthropic.com/v1/models' => MockResponse::make(['error' => 'unauthorized'], 401)]);

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
        $this->configureClaudeAgent();
        config(['services.github.client_id' => null, 'services.github.client_secret' => null]);

        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 3)
            ->assertDontSee('Connect with GitHub');

        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with GitHub');
    }

    public function test_gitlab_connect_button_visible_when_gitlab_oauth_configured(): void
    {
        $this->configureClaudeAgent();
        config([
            'services.gitlab.client_id' => 'gl-cid',
            'services.gitlab.client_secret' => 'gl-cs',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with GitLab');
    }

    public function test_bitbucket_connect_button_visible_when_bitbucket_oauth_configured(): void
    {
        $this->configureClaudeAgent();
        config([
            'services.bitbucket.client_id' => 'bb-cid',
            'services.bitbucket.client_secret' => 'bb-cs',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSee('Connect with Bitbucket');
    }

    /** Create a Claude credential so the wizard's agent gate is satisfied. */
    private function configureClaudeAgent(): void
    {
        AgentCredential::create([
            'agent_name' => AgentName::ClaudeCode->value,
            'name' => 'Default',
            'credentials' => ['token' => 'oat-test'],
            'status' => AgentCredentialStatus::Active->value,
        ]);
    }

    public function test_disconnect_provider_removes_connected_account(): void
    {
        $user = User::factory()->admin()->create();
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
        $this->configureClaudeAgent();
        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::GitLab,
            'instance_url' => 'https://git.example.com',
            'enabled' => true,
        ]);

        // Regression: a self-hosted GitLab OAuth app (instance_url set) is not in
        // config('services.*'), so the old config-only check hid it. It must now
        // surface with an instance-scoped connect link.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 3)
            ->assertSee('git.example.com')
            ->assertSee('instance=');
    }

    public function test_onboarding_lists_stored_tokens_in_authorize_step(): void
    {
        $this->configureClaudeAgent();
        ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitLab,
            'label' => 'CI token',
        ]);

        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 3)
            ->assertSee('CI token');
    }

    public function test_step_navigation_is_gated_by_agent_then_advances(): void
    {
        // Start on the optional security step (1); skipping it reaches agents (2),
        // where next is refused without an agent.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 1)
            ->call('nextStep')
            ->assertSet('currentStep', 2)
            ->call('nextStep')
            ->assertSet('currentStep', 2);

        $this->configureClaudeAgent();

        Livewire::test(Onboarding::class)
            ->call('goToStep', 2)
            ->assertSet('currentStep', 2)
            ->call('nextStep')
            ->assertSet('currentStep', 3);
    }

    public function test_create_project_not_in_header_actions(): void
    {
        Livewire::test(Onboarding::class)
            ->assertActionDoesNotExist('createWorkspace');
    }

    public function test_step2_renders_with_credential_setup_links_when_no_oauth_configured(): void
    {
        $this->configureClaudeAgent();
        config([
            'services.github.client_id' => null,
            'services.gitlab.client_id' => null,
            'services.bitbucket.client_id' => null,
        ]);

        // Regression: the deep links must resolve — the OAuth-config resource
        // slug is pinned, otherwise route() throws RouteNotFoundException here.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 3)
            ->assertSuccessful()
            ->assertSee(route('filament.admin.resources.provider-oauth-configs.create'))
            ->assertSee(route('filament.admin.resources.provider-credentials.create'));
    }

    public function test_step2_offers_adding_more_oauth_apps_when_one_is_configured(): void
    {
        $this->configureClaudeAgent();
        config(['services.github.client_id' => 'cid', 'services.github.client_secret' => 'cs']);

        // Even with GitHub configured, the "add another OAuth app" route must be
        // reachable so further providers / self-hosted instances can be added.
        Livewire::test(Onboarding::class)
            ->assertSet('currentStep', 3)
            ->assertSee('Connect with GitHub')
            ->assertSee(route('filament.admin.resources.provider-oauth-configs.create'));
    }

    public function test_inline_create_with_oauth_account_creates_workspace(): void
    {
        $this->configureClaudeAgent();

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
            ->assertSet('currentStep', 3)
            ->set('repoSource', "oauth:{$account->id}")
            ->set('selectedRepo', 'acme/widget')
            ->set('selectedBranch', 'main')
            ->set('workspaceName', 'widget')
            ->call('createWorkspace')
            ->assertSet('currentStep', 4)
            ->assertSee(__('onboarding.done.create_workspace'))
            ->assertSee('/w/workspaces/'.Workspace::query()->where('name', 'widget')->value('id'));

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'widget',
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'default_branch' => 'main',
            'repo_url' => 'https://github.com/acme/widget',
        ]);
    }

    public function test_inline_create_with_pat_credential_creates_workspace(): void
    {
        $this->configureClaudeAgent();

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
            ->set('workspaceName', 'gadget')
            ->call('createWorkspace')
            ->assertSet('currentStep', 4);

        $workspace = Workspace::query()->where('name', 'gadget')->first();
        $this->assertNotNull($workspace);
        $this->assertSame('pat', $workspace->auth_method->value);
        $this->assertNull($workspace->connected_account_id);
        $this->assertSame('ghp-secret', $workspace->token);
    }

    public function test_inline_create_rejects_duplicate_name(): void
    {
        $this->configureClaudeAgent();
        Workspace::factory()->create(['name' => 'widget']);

        $credential = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
        ]);

        Http::fake(['api.github.com/*' => Http::response([])]);

        Livewire::test(Onboarding::class)
            ->set('repoSource', "pat:{$credential->id}")
            ->set('selectedRepo', 'acme/widget')
            ->set('selectedBranch', 'main')
            ->set('workspaceName', 'widget')
            ->call('createWorkspace')
            ->assertSet('currentStep', 3);

        $this->assertSame(1, Workspace::query()->where('name', 'widget')->count());
    }

    public function test_onboarding_hidden_from_nav_when_project_exists(): void
    {
        Workspace::factory()->create();

        $this->assertFalse(Onboarding::shouldRegisterNavigation());
    }

    public function test_onboarding_visible_in_nav_when_no_project(): void
    {
        $this->assertTrue(Onboarding::shouldRegisterNavigation());
    }

    public function test_the_language_is_chosen_first_and_saved_on_the_account(): void
    {
        Livewire::test(Onboarding::class)
            ->assertActionVisible('localeDe')
            ->callAction('localeDe')
            ->assertRedirect(Onboarding::getUrl());

        $this->assertSame('de', auth()->user()->fresh()->locale);
    }

    public function test_the_wizard_hides_the_panel_navigation(): void
    {
        $this->get(Onboarding::getUrl())->assertOk()->assertSee('argos-onboarding');
    }
}
