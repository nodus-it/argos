<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Models\RepoProfile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_create_requires_name_url_platform(): void
    {
        Livewire::test(CreateRepoProfile::class)
            ->fillForm(['name' => null, 'url' => null, 'platform' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'url' => 'required', 'platform' => 'required']);
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

    public function test_table_shows_platforms(): void
    {
        RepoProfile::factory()->create(['platform' => 'github', 'name' => 'GitHub Repo']);
        RepoProfile::factory()->create(['platform' => 'gitlab', 'name' => 'GitLab Repo']);

        Livewire::test(ListRepoProfiles::class)
            ->assertSee('GitHub Repo')
            ->assertSee('GitLab Repo');
    }
}
