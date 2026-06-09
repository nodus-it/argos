<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuthMethod;
use App\Enums\IntegrationProvider;
use App\Filament\Admin\Resources\RepoProfileResource;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ViewRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TaskProviderBindingsRelationManager;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TasksRelationManager;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class RepoProfileResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_list_page_renders(): void
    {
        $profiles = RepoProfile::factory()->count(3)->create();

        Livewire::test(ListRepoProfiles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($profiles);
    }

    public function test_create_page_renders(): void
    {
        Livewire::test(CreateRepoProfile::class)
            ->assertSuccessful();
    }

    public function test_can_create_repo_profile(): void
    {
        Saloon::fake([
            'api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'name' => 'Test Projekt',
                'url' => 'https://github.com/org/repo',
                'platform' => 'github',
                'token' => 'pat-123',
                'default_branch' => 'main',
                'auto_concept' => false,
                'auto_pr' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(RepoProfile::class, [
            'name' => 'Test Projekt',
            'platform' => 'github',
        ]);
    }

    public function test_can_set_per_project_max_turns(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);
        $profile = RepoProfile::factory()->create(['default_branch' => 'main']);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm([
                'max_turns_concept' => 80,
                'max_turns_implement' => 300,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(RepoProfile::class, [
            'id' => $profile->id,
            'max_turns_concept' => 80,
            'max_turns_implement' => 300,
        ]);
    }

    public function test_create_requires_platform_first(): void
    {
        Livewire::test(CreateRepoProfile::class)
            ->fillForm(['platform' => null])
            ->call('create')
            ->assertHasFormErrors(['platform' => 'required']);
    }

    public function test_create_requires_name_and_url_after_platform_chosen(): void
    {
        Livewire::test(CreateRepoProfile::class)
            ->fillForm(['platform' => 'gitlab', 'name' => null, 'url' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'url' => 'required']);
    }

    public function test_create_rejects_invalid_url(): void
    {
        Livewire::test(CreateRepoProfile::class)
            ->fillForm(['name' => 'Test', 'url' => 'not-a-url', 'platform' => 'github', 'default_branch' => 'main'])
            ->call('create')
            ->assertHasFormErrors(['url']);
    }

    public function test_edit_page_renders_with_data(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertFormSet(['name' => $profile->name]);
    }

    public function test_edit_prefills_github_repo_and_branch_from_persisted_url(): void
    {
        $profile = RepoProfile::factory()->create([
            'platform' => 'github',
            'url' => 'https://github.com/acme/widget',
            'default_branch' => 'develop',
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertFormSet([
                'oauth_repo' => 'acme/widget',
                'oauth_branch' => 'develop',
                'default_branch' => 'develop',
            ]);
    }

    public function test_edit_prefills_handles_dot_git_suffix(): void
    {
        $profile = RepoProfile::factory()->create([
            'platform' => 'github',
            'url' => 'https://github.com/acme/widget.git',
            'default_branch' => 'main',
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertFormSet(['oauth_repo' => 'acme/widget']);
    }

    #[DataProvider('repoPathProvider')]
    public function test_repo_path_from_url_extracts_owner_repo(string $url, ?string $expected): void
    {
        $this->assertSame($expected, RepoProfileResource::repoPathFromUrl($url));
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

    public function test_can_edit_repo_profile(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $profile = RepoProfile::factory()->create();

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['name' => 'Umbenannt', 'auto_pr' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(RepoProfile::class, ['id' => $profile->id, 'name' => 'Umbenannt', 'auto_pr' => true]);
    }

    public function test_can_delete_repo_profile(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(ListRepoProfiles::class)
            ->callAction(TestAction::make('delete')->table($profile))
            ->assertNotified();

        $this->assertDatabaseMissing(RepoProfile::class, ['id' => $profile->id]);
    }

    public function test_oauth_path_creates_repo_profile_with_url_from_github_repo(): void
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

        Livewire::test(CreateRepoProfile::class)
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

        $this->assertDatabaseHas(RepoProfile::class, [
            'name' => 'Widget',
            'platform' => 'github',
            'url' => 'https://github.com/acme/widget',
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

        Livewire::test(CreateRepoProfile::class)
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

    public function test_oauth_path_persists_default_branch_on_save(): void
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
                ['name' => 'feature/php-app'],
            ]),
        ]);

        $profile = RepoProfile::factory()->create([
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'url' => 'https://github.com/acme/widget',
            'default_branch' => 'main',
            'token' => null,
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['oauth_branch' => 'feature/php-app'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(RepoProfile::class, [
            'id' => $profile->id,
            'default_branch' => 'feature/php-app',
        ]);
    }

    public function test_can_create_repo_profile_with_pat(): void
    {
        Saloon::fake([
            'api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'name' => 'PAT Projekt',
                'platform' => 'github',
                'auth_method' => 'pat',
                'url' => 'https://github.com/org/repo',
                'token' => 'ghp_pattoken',
                'default_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(RepoProfile::class, [
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

        Livewire::test(CreateRepoProfile::class)
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

        $this->assertDatabaseHas(RepoProfile::class, [
            'name' => 'OAuth Projekt',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'url' => 'https://github.com/org/repo',
            'default_branch' => 'main',
        ]);
    }

    public function test_switching_to_pat_clears_connected_account_id_on_save(): void
    {
        Saloon::fake([
            'api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        $profile = RepoProfile::factory()->create([
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'token' => null,
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm([
                'auth_method' => 'pat',
                'token' => 'new-pat-token',
                'url' => 'https://github.com/org/repo',
                'default_branch' => 'main',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(RepoProfile::class, [
            'id' => $profile->id,
            'auth_method' => 'pat',
            'connected_account_id' => null,
        ]);
    }

    public function test_table_shows_platforms(): void
    {
        RepoProfile::factory()->create(['platform' => 'github', 'name' => 'GitHub Repo']);
        RepoProfile::factory()->create(['platform' => 'gitlab', 'name' => 'GitLab Repo']);

        Livewire::test(ListRepoProfiles::class)
            ->assertSee('GitHub Repo')
            ->assertSee('GitLab Repo');
    }

    public function test_edit_page_renders(): void
    {
        // Detail = edit (the view page was removed in the redesign).
        $profile = RepoProfile::factory()->create();

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertSee($profile->name);
    }

    public function test_edit_page_includes_tasks_relation_manager(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertSeeLivewire(TasksRelationManager::class);
    }

    public function test_resource_registers_task_provider_bindings_relation_manager(): void
    {
        $this->assertContains(
            TaskProviderBindingsRelationManager::class,
            RepoProfileResource::getRelations(),
        );
    }

    public function test_binding_create_form_loads_project_refs_from_provider(): void
    {
        $profile = RepoProfile::factory()->create();
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
            'ownerRecord' => $profile,
            'pageClass' => EditRepoProfile::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'github',
                'mode' => 'poll',
                'credential_ref' => "oauth:{$account->id}",
                'external_project_ref' => 'acme/widget',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(TaskProviderBinding::class, [
            'repo_profile_id' => $profile->id,
            'kind' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'external_project_ref' => 'acme/widget',
        ]);
    }

    public function test_binding_create_form_persists_pat_credential(): void
    {
        $profile = RepoProfile::factory()->create();
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
            'ownerRecord' => $profile,
            'pageClass' => EditRepoProfile::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'github',
                'mode' => 'poll',
                'credential_ref' => "pat:{$credential->id}",
                'external_project_ref' => 'acme/widget',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(TaskProviderBinding::class, [
            'repo_profile_id' => $profile->id,
            'kind' => 'github',
            'auth_method' => 'pat',
            'connected_account_id' => null,
            'provider_credential_id' => $credential->id,
        ]);
    }

    public function test_binding_edit_prefills_credential_ref_for_pat(): void
    {
        $profile = RepoProfile::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
        ]);
        $binding = TaskProviderBinding::factory()->pat($credential)->create([
            'repo_profile_id' => $profile->id,
            'kind' => 'github',
        ]);

        Livewire::test(TaskProviderBindingsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => EditRepoProfile::class,
        ])
            ->mountAction(TestAction::make('edit')->table($binding))
            ->assertActionDataSet([
                'credential_ref' => "pat:{$credential->id}",
            ]);

        $this->assertSame(AuthMethod::Pat, $binding->fresh()->auth_method);
    }

    public function test_binding_account_options_are_filtered_by_provider(): void
    {
        $profile = RepoProfile::factory()->create();
        $githubAccount = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        // Picking Linear must exclude the GitHub account from the account
        // options, so selecting it fails the Select's implicit `in` rule.
        Livewire::test(TaskProviderBindingsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => EditRepoProfile::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'linear',
                'mode' => 'poll',
                'credential_ref' => "oauth:{$githubAccount->id}",
            ])
            ->assertHasActionErrors(['credential_ref']);
    }

    public function test_tasks_relation_manager_shows_tasks(): void
    {
        $profile = RepoProfile::factory()->create();
        $tasks = Task::factory()->count(2)->create(['repo_profile_id' => $profile->id]);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewRepoProfile::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($tasks);
    }

    public function test_tasks_relation_manager_does_not_show_other_profile_tasks(): void
    {
        $profile = RepoProfile::factory()->create();
        $otherProfile = RepoProfile::factory()->create();
        $ownTask = Task::factory()->create(['repo_profile_id' => $profile->id]);
        $foreignTask = Task::factory()->create(['repo_profile_id' => $otherProfile->id]);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewRepoProfile::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownTask])
            ->assertCanNotSeeTableRecords([$foreignTask]);
    }

    public function test_can_create_bitbucket_repo_profile_with_pat(): void
    {
        Saloon::fake([
            'api.bitbucket.org/2.0/repositories/myworkspace/myrepo/refs/branches*' => MockResponse::make([
                'values' => [['name' => 'main']],
            ]),
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'name' => 'Bitbucket Projekt',
                'platform' => 'bitbucket',
                'auth_method' => 'pat',
                'url' => 'https://bitbucket.org/myworkspace/myrepo',
                'token' => 'myuser:myapppassword',
                'default_branch' => 'main',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(RepoProfile::class, [
            'name' => 'Bitbucket Projekt',
            'platform' => 'bitbucket',
            'url' => 'https://bitbucket.org/myworkspace/myrepo',
            'auth_method' => 'pat',
        ]);
    }

    public function test_bitbucket_oauth_path_creates_repo_profile(): void
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

        Livewire::test(CreateRepoProfile::class)
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

        $this->assertDatabaseHas(RepoProfile::class, [
            'name' => 'Widget',
            'platform' => 'bitbucket',
            'url' => 'https://bitbucket.org/acme/widget',
            'default_branch' => 'main',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
        ]);
    }

    public function test_edit_prefills_bitbucket_repo_and_branch_from_persisted_url(): void
    {
        $profile = RepoProfile::factory()->create([
            'platform' => 'bitbucket',
            'url' => 'https://bitbucket.org/acme/myrepo',
            'default_branch' => 'develop',
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertFormSet([
                'oauth_repo' => 'acme/myrepo',
                'oauth_branch' => 'develop',
                'default_branch' => 'develop',
            ]);
    }

    public function test_table_shows_bitbucket_platform(): void
    {
        RepoProfile::factory()->create(['platform' => 'bitbucket', 'name' => 'Bitbucket Repo']);

        Livewire::test(ListRepoProfiles::class)
            ->assertSee('Bitbucket Repo');
    }

    public function test_env_secrets_round_trip_via_edit_form(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $profile = RepoProfile::factory()->create(['platform' => 'github']);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm([
                'composer_registries' => [
                    ['host' => 'packages.filamentphp.com', 'username' => 'u', 'token' => 'sek'],
                ],
                'worker_env' => [
                    ['name' => 'MEILI_KEY', 'value' => 'abc'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $profile->fresh();
        $this->assertSame('packages.filamentphp.com', $fresh->composer_registries[0]['host']);
        $this->assertSame('abc', $fresh->worker_env[0]['value']);
    }

    public function test_worker_services_round_trip_via_edit_form(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $profile = RepoProfile::factory()->create(['platform' => 'github']);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['worker_services' => ['mysql', 'redis']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['mysql', 'redis'], $profile->fresh()->worker_services);
    }
}
