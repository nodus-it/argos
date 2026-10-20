<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        RepoProfile::factory()->create();
    }

    private function workspaceFor(User $user, WorkspaceRole $role = WorkspaceRole::Owner, array $attributes = []): Workspace
    {
        $workspace = Workspace::factory()->create($attributes);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $workspace;
    }

    public function test_members_list_only_their_workspaces_admins_see_all(): void
    {
        $me = User::factory()->create();
        $mine = $this->workspaceFor($me);
        $other = $this->workspaceFor(User::factory()->create());

        $this->actingAs($me);
        Livewire::test(ListWorkspaces::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ListWorkspaces::class)->assertCanSeeTableRecords([$mine, $other]);
    }

    public function test_creating_a_workspace_queues_provisioning_and_opens_it(): void
    {
        $user = User::factory()->create();
        $profile = RepoProfile::factory()->create(['default_branch' => 'main']);
        $this->actingAs($user);

        Livewire::test(CreateWorkspace::class)
            ->fillForm(['repo_profile_id' => $profile->id, 'name' => 'Checkout flow'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $workspace = Workspace::query()->where('slug', 'checkout-flow')->firstOrFail();
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($user));
        $this->assertSame('main', $workspace->base_branch);
        Queue::assertPushed(ProvisionWorkspaceJob::class);
    }

    public function test_capacity_errors_are_shown_instead_of_creating(): void
    {
        config(['argos.workspaces.max_active' => 1]);
        Workspace::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateWorkspace::class)
            ->fillForm(['repo_profile_id' => RepoProfile::query()->first()->id, 'name' => 'Too many'])
            ->call('create')
            ->assertNotified();

        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_the_workspace_page_is_members_only(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me);

        $this->actingAs($me)->get(ViewWorkspace::getUrl(['record' => $workspace]))->assertOk()->assertSeeLivewire('workspace.thread-chat');
        $this->actingAs(User::factory()->create())->get(ViewWorkspace::getUrl(['record' => $workspace]))->assertNotFound();
    }

    public function test_opening_a_stopped_workspace_starts_it_for_operators(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me, WorkspaceRole::Editor, ['status' => WorkspaceStatus::Stopped]);

        Livewire::actingAs($me)->test(ViewWorkspace::class, ['record' => $workspace->getKey()]);

        $this->assertSame(WorkspaceStatus::Starting, $workspace->fresh()->status);
        Queue::assertPushed(ProvisionWorkspaceJob::class);
    }

    public function test_viewers_do_not_start_a_stopped_workspace(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me, WorkspaceRole::Viewer, ['status' => WorkspaceStatus::Stopped]);

        Livewire::actingAs($me)->test(ViewWorkspace::class, ['record' => $workspace->getKey()]);

        $this->assertSame(WorkspaceStatus::Stopped, $workspace->fresh()->status);
    }

    public function test_owners_add_members_through_the_page(): void
    {
        $owner = User::factory()->create();
        $pair = User::factory()->create(['name' => 'Pair']);
        $workspace = $this->workspaceFor($owner);

        Livewire::actingAs($owner)->test(ViewWorkspace::class, ['record' => $workspace->getKey()])
            ->callAction('members', ['user_id' => $pair->id, 'role' => WorkspaceRole::Editor->value])
            ->assertNotified();

        $this->assertSame(WorkspaceRole::Editor, $workspace->roleOf($pair));
    }

    public function test_member_management_is_hidden_without_collaboration(): void
    {
        config(['argos.workspaces.collaboration' => false]);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        Livewire::actingAs($owner)->test(ViewWorkspace::class, ['record' => $workspace->getKey()])
            ->assertActionHidden('members');
    }
}
