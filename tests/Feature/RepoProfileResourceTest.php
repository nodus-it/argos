<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ViewRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TasksRelationManager;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\Git\RemoteBranchValidator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

        // Bypass actual `git ls-remote` calls in unit tests — the offline test
        // suite must not touch external services.
        $this->fakeBranchValidator(['ok' => true, 'error' => null]);
    }

    /**
     * @param  array{ok: bool, error: string|null}  $result
     */
    private function fakeBranchValidator(array $result): void
    {
        $fake = new class($result) extends RemoteBranchValidator
        {
            /**
             * @param  array{ok: bool, error: string|null}  $result
             */
            public function __construct(private readonly array $result) {}

            public function validate(string $url, string $branch, ?string $token = null): array
            {
                return $this->result;
            }
        };

        $this->app->instance(RemoteBranchValidator::class, $fake);
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

    public function test_create_rejects_default_branch_missing_on_remote(): void
    {
        $this->fakeBranchValidator([
            'ok' => false,
            'error' => "Branch 'main' nicht im Repository gefunden.",
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'name' => 'Test',
                'url' => 'https://github.com/foo/bar',
                'platform' => 'github',
                'default_branch' => 'main',
                'token' => 'pat-123',
            ])
            ->call('create')
            ->assertHasFormErrors(['default_branch']);
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
                'github_repo' => 'acme/widget',
                'github_branch' => 'develop',
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
            ->assertFormSet(['github_repo' => 'acme/widget']);
    }

    public function test_can_edit_repo_profile(): void
    {
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

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'acme/widget'],
            ]),
            'api.github.com/repos/acme/widget' => Http::response([
                'default_branch' => 'main',
            ]),
            'api.github.com/repos/acme/widget/branches*' => Http::response([
                ['name' => 'main'],
                ['name' => 'develop'],
            ]),
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'platform' => 'github',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'name' => 'Widget',
                'github_repo' => 'acme/widget',
                'github_branch' => 'main',
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

    public function test_oauth_path_persists_default_branch_on_save(): void
    {
        $account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => 'github',
        ]);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'acme/widget'],
            ]),
            'api.github.com/repos/acme/widget/branches*' => Http::response([
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
            ->fillForm(['github_branch' => 'feature/php-app'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(RepoProfile::class, [
            'id' => $profile->id,
            'default_branch' => 'feature/php-app',
        ]);
    }

    public function test_can_create_repo_profile_with_pat(): void
    {
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

        Http::fake([
            'api.github.com/user/repos*' => Http::response([['full_name' => 'org/repo']]),
            'api.github.com/repos/org/repo/branches*' => Http::response([['name' => 'main']]),
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'name' => 'OAuth Projekt',
                'platform' => 'github',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'github_repo' => 'org/repo',
                'github_branch' => 'main',
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

    public function test_view_page_renders(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(ViewRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertSee($profile->name);
    }

    public function test_view_page_masks_token(): void
    {
        $profile = RepoProfile::factory()->create(['token' => 'secret-pat-token']);

        Livewire::test(ViewRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertSee('••••••••')
            ->assertDontSee('secret-pat-token');
    }

    public function test_view_page_shows_no_token_placeholder_when_empty(): void
    {
        $profile = RepoProfile::factory()->create(['token' => null]);

        Livewire::test(ViewRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertDontSee('••••••••');
    }

    public function test_view_page_includes_tasks_relation_manager(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(ViewRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->assertSeeLivewire(TasksRelationManager::class);
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

        // More-specific patterns must come first; Http::fake matches in definition order.
        Http::fake([
            'api.bitbucket.org/2.0/repositories/acme/widget/refs/branches*' => Http::response([
                'values' => [['name' => 'main'], ['name' => 'develop']],
            ]),
            'api.bitbucket.org/2.0/repositories/acme/widget' => Http::response([
                'mainbranch' => ['name' => 'main'],
            ]),
            'api.bitbucket.org/2.0/user/permissions/workspaces*' => Http::response([
                'values' => [['workspace' => ['slug' => 'acme']]],
            ]),
            'api.bitbucket.org/2.0/repositories/acme*' => Http::response([
                'values' => [['full_name' => 'acme/widget']],
            ]),
        ]);

        Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'platform' => 'bitbucket',
                'auth_method' => 'oauth',
                'connected_account_id' => $account->id,
                'name' => 'Widget',
                'bitbucket_repo' => 'acme/widget',
                'bitbucket_branch' => 'main',
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
                'bitbucket_repo' => 'acme/myrepo',
                'bitbucket_branch' => 'develop',
                'default_branch' => 'develop',
            ]);
    }

    public function test_table_shows_bitbucket_platform(): void
    {
        RepoProfile::factory()->create(['platform' => 'bitbucket', 'name' => 'Bitbucket Repo']);

        Livewire::test(ListRepoProfiles::class)
            ->assertSee('Bitbucket Repo');
    }
}
