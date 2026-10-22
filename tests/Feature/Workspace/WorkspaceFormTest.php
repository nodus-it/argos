<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\AuthMethod;
use App\Enums\IntegrationProvider;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\EditWorkspaceSettings;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Workspaces\Resources\WorkspaceResource\RelationManagers\TaskProviderBindingsRelationManager;
use App\Filament\Shared\WorkspaceForm;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

/** The shared workspace form: creating (all platforms and auth paths), settings and trackers. */
class WorkspaceFormTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    public function test_list_page_renders(): void
    {
        $workspaces = Workspace::factory()->count(3)->create();

        Livewire::test(ListWorkspaces::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($workspaces);
    }

    public function test_create_page_renders(): void
    {
        Livewire::test(CreateWorkspace::class)
            ->assertSuccessful();
    }

    public function test_can_create_workspace(): void
    {
        Saloon::fake([
            'api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'name' => 'Test Projekt',
                'repo_url' => 'https://github.com/org/repo',
                'platform' => 'github',
                'token' => 'pat-123',
                'default_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'Test Projekt',
            'platform' => 'github',
        ]);
    }

    public function test_create_requires_platform_first(): void
    {
        Livewire::test(CreateWorkspace::class)
            ->fillForm(['platform' => null])
            ->call('create')
            ->assertHasFormErrors(['platform' => 'required']);
    }

    public function test_create_requires_name_and_url_after_platform_chosen(): void
    {
        Livewire::test(CreateWorkspace::class)
            ->fillForm(['platform' => 'gitlab', 'name' => null, 'repo_url' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'repo_url' => 'required']);
    }

    public function test_create_rejects_invalid_url(): void
    {
        Livewire::test(CreateWorkspace::class)
            ->fillForm(['name' => 'Test', 'repo_url' => 'not-a-url', 'platform' => 'github', 'default_branch' => 'main'])
            ->call('create')
            ->assertHasFormErrors(['repo_url']);
    }

    public function test_edit_page_renders_with_data(): void
    {
        $workspace = Workspace::factory()->create();

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSuccessful()
            ->assertFormSet(['name' => $workspace->name]);
    }

    #[DataProvider('repoPathProvider')]
    public function test_repo_path_from_url_extracts_owner_repo(string $url, ?string $expected): void
    {
        $this->assertSame($expected, WorkspaceForm::repoPathFromUrl($url));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function repoPathProvider(): array
    {
        return [
            'github' => ['https://github.com/acme/widget', 'acme/widget'],
            'github dot-git' => ['https://github.com/acme/widget.git', 'acme/widget'],
            'github trailing slash' => ['https://github.com/acme/widget/', 'acme/widget'],
            'bitbucket' => ['https://bitbucket.org/acme/myrepo', 'acme/myrepo'],
            'gitlab nested groups' => ['https://gitlab.com/group/sub/widget', 'group/sub/widget'],
            'gitlab self-hosted' => ['https://gitlab.example.com/team/widget.git', 'team/widget'],
            'no path' => ['https://github.com', null],
        ];
    }

    public function test_can_edit_workspace(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $workspace = Workspace::factory()->create();

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['name' => 'Umbenannt'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Workspace::class, ['id' => $workspace->id, 'name' => 'Umbenannt']);
    }

    public function test_the_repository_stays_fixed_when_editing(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);
        $workspace = Workspace::factory()->create();
        $repo = $workspace->repo_url;

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['name' => 'Shop'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($repo, $workspace->fresh()->repo_url);
        $this->assertSame('github', $workspace->fresh()->platform->value);

        // The service ignores them, whoever calls it.
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        app(\App\Services\Workspace\WorkspaceService::class)->updateSettings($workspace, $admin, ['repo_url' => 'https://github.com/evil/other', 'platform' => 'gitlab', 'name' => 'Renamed']);
        $this->assertSame($repo, $workspace->fresh()->repo_url);
        $this->assertSame('github', $workspace->fresh()->platform->value);
        $this->assertSame('Renamed', $workspace->fresh()->name);
    }

    public function test_can_delete_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->mock(\App\Services\Workspace\WorkspaceTeardown::class)->shouldReceive('purge')->once();

        Livewire::test(\App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace::class, ['record' => $workspace->getKey()])
            ->callAction('delete')
            ->assertRedirect(WorkspaceResource::getUrl('index'));

        $this->assertSoftDeleted($workspace);
    }

    public function test_oauth_path_creates_workspace_with_url_from_github_repo(): void
    {
        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        Saloon::fake([
            'api.github.com/user/repos*' => MockResponse::make([
                ['full_name' => 'acme/widget'],
            ]),
            'api.github.com/repos/acme/widget/branches*' => MockResponse::make([
                ['name' => 'main'],
                ['name' => 'develop'],
            ]),
            'api.github.com/repos/acme/widget' => MockResponse::make([
                'default_branch' => 'main',
            ]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'platform' => 'github',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'name' => 'Widget',
                'oauth_repo' => 'acme/widget',
                'oauth_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'Widget',
            'platform' => 'github',
            'repo_url' => 'https://github.com/acme/widget',
            'default_branch' => 'main',
            'auth_method' => 'oauth',
        ]);
    }

    public function test_gitlab_oauth_path_preselects_default_branch_from_api(): void
    {
        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'gitlab',
        ]);

        // More-specific patterns first; Saloon matches in definition order.
        Saloon::fake([
            'gitlab.com/api/v4/projects/acme%2Fwidget/repository/branches*' => MockResponse::make([
                ['name' => 'main'],
                ['name' => 'develop'],
            ]),
            'gitlab.com/api/v4/projects/acme%2Fwidget' => MockResponse::make([
                'default_branch' => 'develop',
            ]),
            'gitlab.com/api/v4/projects*' => MockResponse::make([
                ['path_with_namespace' => 'acme/widget'],
            ]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'platform' => 'gitlab',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'name' => 'Widget',
                'oauth_repo' => 'acme/widget',
            ])
            ->assertFormSet([
                'oauth_branch' => 'develop',
                'default_branch' => 'develop',
            ]);
    }

    public function test_can_create_workspace_with_pat(): void
    {
        Saloon::fake([
            'api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'name' => 'PAT Projekt',
                'platform' => 'github',
                'auth_method' => 'pat',
                'repo_url' => 'https://github.com/org/repo',
                'token' => 'ghp_pattoken',
                'default_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'PAT Projekt',
            'auth_method' => 'pat',
            'connected_account_id' => null,
        ]);
    }

    public function test_create_with_oauth_saves_connected_account_id(): void
    {
        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        Saloon::fake([
            'api.github.com/user/repos*' => MockResponse::make([['full_name' => 'org/repo']]),
            'api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'name' => 'OAuth Projekt',
                'platform' => 'github',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'oauth_repo' => 'org/repo',
                'oauth_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'OAuth Projekt',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'repo_url' => 'https://github.com/org/repo',
            'default_branch' => 'main',
        ]);
    }

    public function test_table_shows_platforms(): void
    {
        Workspace::factory()->create(['platform' => 'github', 'name' => 'GitHub Repo']);
        Workspace::factory()->create(['platform' => 'gitlab', 'name' => 'GitLab Repo']);

        Livewire::test(ListWorkspaces::class)
            ->assertSee('GitHub Repo')
            ->assertSee('GitLab Repo');
    }

    public function test_edit_page_renders(): void
    {
        // Detail = edit (the view page was removed in the redesign).
        $workspace = Workspace::factory()->create();

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSuccessful()
            ->assertSee($workspace->name);
    }

    public function test_edit_page_includes_task_provider_bindings_relation_manager(): void
    {
        $workspace = Workspace::factory()->create();

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSuccessful()
            ->assertSeeLivewire(TaskProviderBindingsRelationManager::class);
    }

    public function test_binding_create_form_loads_project_refs_from_provider(): void
    {
        $workspace = Workspace::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        Saloon::fake([
            'api.github.com/user/repos*' => MockResponse::make([
                ['full_name' => 'acme/widget'],
                ['full_name' => 'acme/gadget'],
            ]),
        ]);

        Livewire::test(TaskProviderBindingsRelationManager::class, [
            'ownerRecord' => $workspace,
            'pageClass' => EditWorkspaceSettings::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'github',
                'mode' => 'poll',
                'credential_ref' => "oauth:{$account->id}",
                'external_project_ref' => 'acme/widget',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(TaskProviderBinding::class, [
            'workspace_id' => $workspace->id,
            'kind' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'external_project_ref' => 'acme/widget',
        ]);
    }

    public function test_binding_create_form_persists_pat_credential(): void
    {
        $workspace = Workspace::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'label' => 'CI PAT',
        ]);

        Saloon::fake([
            'api.github.com/user/repos*' => MockResponse::make([
                ['full_name' => 'acme/widget'],
            ]),
        ]);

        Livewire::test(TaskProviderBindingsRelationManager::class, [
            'ownerRecord' => $workspace,
            'pageClass' => EditWorkspaceSettings::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'github',
                'mode' => 'poll',
                'credential_ref' => "pat:{$credential->id}",
                'external_project_ref' => 'acme/widget',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(TaskProviderBinding::class, [
            'workspace_id' => $workspace->id,
            'kind' => 'github',
            'auth_method' => 'pat',
            'connected_account_id' => null,
            'provider_credential_id' => $credential->id,
        ]);
    }

    public function test_binding_edit_prefills_credential_ref_for_pat(): void
    {
        $workspace = Workspace::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
        ]);
        $binding = TaskProviderBinding::factory()->pat($credential)->create([
            'workspace_id' => $workspace->id,
            'kind' => 'github',
        ]);

        Livewire::test(TaskProviderBindingsRelationManager::class, [
            'ownerRecord' => $workspace,
            'pageClass' => EditWorkspaceSettings::class,
        ])
            ->mountAction(TestAction::make('edit')->table($binding))
            ->assertActionDataSet([
                'credential_ref' => "pat:{$credential->id}",
            ]);

        $this->assertSame(AuthMethod::Pat, $binding->fresh()->auth_method);
    }

    public function test_binding_account_options_are_filtered_by_provider(): void
    {
        $workspace = Workspace::factory()->create();
        $githubAccount = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        // Picking Linear must exclude the GitHub account from the account
        // options, so selecting it fails the Select's implicit `in` rule.
        Livewire::test(TaskProviderBindingsRelationManager::class, [
            'ownerRecord' => $workspace,
            'pageClass' => EditWorkspaceSettings::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'linear',
                'mode' => 'poll',
                'credential_ref' => "oauth:{$githubAccount->id}",
            ])
            ->assertHasActionErrors(['credential_ref']);
    }

    public function test_can_create_bitbucket_workspace_with_pat(): void
    {
        Saloon::fake([
            'api.bitbucket.org/2.0/repositories/myworkspace/myrepo/refs/branches*' => MockResponse::make([
                'values' => [['name' => 'main']],
            ]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'name' => 'Bitbucket Projekt',
                'platform' => 'bitbucket',
                'auth_method' => 'pat',
                'repo_url' => 'https://bitbucket.org/myworkspace/myrepo',
                'token' => 'myuser:myapppassword',
                'default_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'Bitbucket Projekt',
            'platform' => 'bitbucket',
            'repo_url' => 'https://bitbucket.org/myworkspace/myrepo',
            'auth_method' => 'pat',
        ]);
    }

    public function test_bitbucket_oauth_path_creates_workspace(): void
    {
        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'bitbucket',
        ]);

        // More-specific patterns must come first; Saloon matches in definition order.
        Saloon::fake([
            'api.bitbucket.org/2.0/repositories/acme/widget/refs/branches*' => MockResponse::make([
                'values' => [['name' => 'main'], ['name' => 'develop']],
            ]),
            'api.bitbucket.org/2.0/repositories/acme/widget' => MockResponse::make([
                'mainbranch' => ['name' => 'main'],
            ]),
            'api.bitbucket.org/2.0/user/workspaces*' => MockResponse::make([
                'values' => [['workspace' => ['slug' => 'acme']]],
            ]),
            'api.bitbucket.org/2.0/repositories/acme*' => MockResponse::make([
                'values' => [['full_name' => 'acme/widget']],
            ]),
        ]);

        Livewire::test(CreateWorkspace::class)
            ->fillForm([
                'platform' => 'bitbucket',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'name' => 'Widget',
                'oauth_repo' => 'acme/widget',
                'oauth_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(Workspace::class, [
            'name' => 'Widget',
            'platform' => 'bitbucket',
            'repo_url' => 'https://bitbucket.org/acme/widget',
            'default_branch' => 'main',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
        ]);
    }

    public function test_table_shows_bitbucket_platform(): void
    {
        Workspace::factory()->create(['platform' => 'bitbucket', 'name' => 'Bitbucket Repo']);

        Livewire::test(ListWorkspaces::class)
            ->assertSee('Bitbucket Repo');
    }

    public function test_env_secrets_round_trip_via_edit_form(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $workspace = Workspace::factory()->create(['platform' => 'github']);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm([
                'composer_registries' => [
                    ['host' => 'packages.filamentphp.com', 'username' => 'u', 'token' => 'sek'],
                ],
                'worker_env' => [
                    ['name' => 'MEILI_KEY', 'value' => 'abc'],
                ],
            ], 'environmentForm')
            ->call('saveEnvironment')
            ->assertHasNoFormErrors([], 'environmentForm');

        $fresh = $workspace->fresh();
        $this->assertSame('packages.filamentphp.com', $fresh->composer_registries[0]['host']);
        $this->assertSame('abc', $fresh->worker_env[0]['value']);
    }

    public function test_worker_services_round_trip_via_edit_form(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $workspace = Workspace::factory()->create(['platform' => 'github']);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['worker_services' => ['mysql', 'redis']], 'environmentForm')
            ->call('saveEnvironment')
            ->assertHasNoFormErrors([], 'environmentForm');

        $this->assertEqualsCanonicalizing(['mysql', 'redis'], $workspace->fresh()->worker_services);
    }

    public function test_mysql_credentials_round_trip_via_edit_form(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $workspace = Workspace::factory()->create(['platform' => 'github']);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm([
                'worker_services' => ['mysql'],
                'worker_service_config' => ['mysql' => [
                    'database' => 'shop',
                    'username' => 'sa',
                    'password' => 'pw',
                ]],
            ], 'environmentForm')
            ->call('saveEnvironment')
            ->assertHasNoFormErrors([], 'environmentForm');

        $config = $workspace->fresh()->worker_service_config;
        $this->assertSame('shop', $config['mysql']['database']);
        $this->assertSame('sa', $config['mysql']['username']);
    }

    public function test_the_settings_renew_the_repository_access(): void
    {
        $workspace = Workspace::factory()->create(['platform' => 'github', 'auth_method' => 'pat', 'token' => 'old-token']);

        // The stored token never reaches the browser; empty keeps it.
        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSet('access.auth_method', 'pat')
            ->assertSet('access.token', null)
            ->call('saveAccess')
            ->assertHasNoFormErrors([], 'accessForm');
        $this->assertSame('old-token', $workspace->fresh()->token);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['token' => 'new-token'], 'accessForm')
            ->call('saveAccess')
            ->assertHasNoFormErrors([], 'accessForm');
        $this->assertSame('new-token', $workspace->fresh()->token);
    }

    public function test_the_settings_switch_between_token_and_connected_account(): void
    {
        $account = ConnectedAccount::factory()->create(['user_id' => $this->user->id, 'provider' => 'github']);
        $workspace = Workspace::factory()->create(['platform' => 'github', 'auth_method' => 'pat', 'token' => 'old-token']);

        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['auth_method' => 'oauth', 'connected_account_id' => $account->id], 'accessForm')
            ->call('saveAccess')
            ->assertHasNoFormErrors([], 'accessForm');
        $fresh = $workspace->fresh();
        $this->assertSame(['oauth', $account->id, null], [$fresh->auth_method->value, $fresh->connected_account_id, $fresh->token]);

        // Back to a token: one is required now.
        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['auth_method' => 'pat'], 'accessForm')
            ->call('saveAccess')
            ->assertHasFormErrors(['token' => 'required'], 'accessForm')
            ->fillForm(['auth_method' => 'pat', 'token' => 'fresh-token'], 'accessForm')
            ->call('saveAccess')
            ->assertHasNoFormErrors([], 'accessForm');
        $fresh = $workspace->fresh();
        $this->assertSame(['pat', null, 'fresh-token'], [$fresh->auth_method->value, $fresh->connected_account_id, $fresh->token]);
    }
}
