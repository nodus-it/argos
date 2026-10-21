<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Livewire\Workspace\ContextPanel;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
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
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Queue::fake();
        Workspace::factory()->create();
    }

    private function workspaceFor(User $user, WorkspaceRole $role = WorkspaceRole::Admin, array $attributes = []): Workspace
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

    public function test_only_argos_admins_get_the_new_workspace_action(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ListWorkspaces::class)->assertActionHidden('create');

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ListWorkspaces::class)
            ->assertActionVisible('create')
            ->assertActionHasUrl('create', WorkspaceResource::getUrl('create'));
    }

    public function test_the_workspace_page_is_members_only(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me);

        $this->actingAs($me)->get(ViewWorkspace::getUrl(['record' => $workspace]))->assertOk()->assertSeeLivewire('workspace.task-chat');
        $this->actingAs(User::factory()->create())->get(ViewWorkspace::getUrl(['record' => $workspace]))->assertNotFound();
    }

    public function test_opening_a_stopped_workspace_starts_it_for_operators(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me, WorkspaceRole::Member, ['status' => WorkspaceStatus::Stopped]);

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

    public function test_workspace_admins_reach_the_settings_from_the_page(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);
        $member = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);

        Livewire::actingAs($admin)->test(ViewWorkspace::class, ['record' => $workspace->getKey()])
            ->assertActionVisible('settings')
            ->assertActionHasUrl('settings', WorkspaceResource::getUrl('settings', ['record' => $workspace]));
        Livewire::actingAs($member)->test(ViewWorkspace::class, ['record' => $workspace->getKey()])
            ->assertActionHidden('settings');
    }

    public function test_the_editor_opens_in_its_own_reused_tab(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me);
        $openUrl = route('workspaces.open', ['workspace' => $workspace, 'target' => 'code']);

        $this->actingAs($me)->get(ViewWorkspace::getUrl(['record' => $workspace]))
            ->assertDontSee('<iframe', false);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Livewire::withoutLazyLoading()->actingAs($me)->test(ContextPanel::class, ['workspaceId' => $workspace->id])
            ->assertSeeHtml('href="'.$openUrl.'" target="argos-code-'.$workspace->slug.'"');
    }

    public function test_code_tab_explains_a_stopped_workspace(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me, WorkspaceRole::Member, ['status' => WorkspaceStatus::Failed]);

        $this->actingAs($me)->get(ViewWorkspace::getUrl(['record' => $workspace]))
            ->assertSee(__('workspaces.states.failed'));
        Livewire::withoutLazyLoading()->actingAs($me)->test(ContextPanel::class, ['workspaceId' => $workspace->id])
            ->assertSee(__('workspaces.focus.code_off'))
            ->assertSee(__('workspaces.focus.unavailable'));
    }

    public function test_viewers_do_not_get_the_editor(): void
    {
        $me = User::factory()->create();
        $workspace = $this->workspaceFor($me, WorkspaceRole::Viewer);

        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Livewire::withoutLazyLoading()->actingAs($me)->test(ContextPanel::class, ['workspaceId' => $workspace->id])
            ->assertSee(__('workspaces.focus.code_viewer'))
            ->assertDontSeeHtml('/open/code');
    }
}
