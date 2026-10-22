<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\AutoTaskState;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\MyWork;
use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\App\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\MyWorkOverview;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class MyWorkTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('app');
        Event::fake([TaskStream::class, UserWorkspaceSignal::class]);
        $this->workspace = Workspace::factory()->create(['name' => 'Shop API']);
        $this->me = $this->member($this->workspace, 'Ada Lovelace');
        $this->other = $this->member($this->workspace, 'Bob Builder');
    }

    private function member(Workspace $workspace, string $name, WorkspaceRole $role = WorkspaceRole::Member): User
    {
        $user = User::factory()->create(['name' => $name, 'locale' => 'en']);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function task(string $title, array $attributes = [], ?User $owner = null): Task
    {
        return Task::factory()->ownedBy($owner ?? $this->me)->create(['workspace_id' => $this->workspace->id, 'title' => $title, ...$attributes]);
    }

    public function test_w_lands_on_my_work(): void
    {
        $this->actingAs($this->me)->get('/w')->assertRedirect(MyWork::getUrl(panel: 'app'));
        $this->assertSame(MyWork::getUrl(panel: 'app'), Filament::getPanel('app')->getHomeUrl());
    }

    public function test_page_renders_in_the_workspaces_panel_with_the_rail(): void
    {
        $this->task('Login form');

        $this->actingAs($this->me)->get(MyWork::getUrl(panel: 'app'))
            ->assertOk()
            ->assertSee(__('workspaces.my_work.title'))
            ->assertSee('Login form')
            ->assertSee('ag-rail', false)
            ->assertSeeLivewire('workspace.rail-workspaces');
    }

    public function test_groups_waiting_running_pr_open_then_ready(): void
    {
        $this->task('Ready one', ['updated_at' => now()->subHours(3)]);
        $this->task('Ready newer', ['updated_at' => now()->subHour()]);
        $this->task('Has a PR', ['status' => TaskStatus::PrOpen, 'pr_number' => 7, 'pr_state' => 'open', 'pr_url' => 'https://github.com/o/r/pull/7']);
        $this->task('Is running', ['session_status' => SessionStatus::Running]);
        $approval = $this->task('Needs approval', ['session_status' => SessionStatus::Running]);
        TaskApproval::factory()->create(['task_id' => $approval->id]);
        $this->task('Waits for input', ['session_status' => SessionStatus::WaitingInput]);
        $this->task('Plan to approve', ['auto_state' => AutoTaskState::AwaitingPlanApproval]);
        $this->task('Finished', ['status' => TaskStatus::Done]);
        $this->task('Archived', ['archived_at' => now()]);

        $overview = app(MyWorkOverview::class)->for($this->me);
        $titles = fn (string $group): array => array_map(fn (array $row): string => $row['task']->title, $overview[$group]);

        $this->assertEqualsCanonicalizing(['Needs approval', 'Waits for input', 'Plan to approve'], $titles(MyWorkOverview::WAITING));
        $this->assertSame(['Is running'], $titles(MyWorkOverview::RUNNING));
        $this->assertSame(['Has a PR'], $titles(MyWorkOverview::PR_OPEN));
        $this->assertSame(['Ready newer', 'Ready one'], $titles(MyWorkOverview::READY));

        Livewire::actingAs($this->me)->test(MyWork::class)
            ->assertSeeInOrder([
                __('workspaces.my_work.groups.waiting'), 'Waits for input',
                __('workspaces.my_work.groups.running'), 'Is running',
                __('workspaces.my_work.groups.pr_open'), 'Has a PR',
                __('workspaces.my_work.groups.ready'), 'Ready newer', 'Ready one',
            ])
            ->assertDontSee('Finished')
            ->assertDontSee('Archived');
    }

    public function test_only_own_and_invited_tasks_show(): void
    {
        $this->task('Mine');
        $invited = $this->task('Bobs with me', [], $this->other);
        TaskMember::query()->create(['task_id' => $invited->id, 'user_id' => $this->me->id, 'invited_by' => $this->other->id]);
        $this->task('Bobs alone', [], $this->other);
        // A task in a workspace the user left stays hidden.
        $gone = Workspace::factory()->create();
        Task::factory()->ownedBy($this->me)->create(['workspace_id' => $gone->id, 'title' => 'Left behind']);

        Livewire::actingAs($this->me)->test(MyWork::class)
            ->assertSee('Mine')
            ->assertSee('Bobs with me')
            // The owner's avatar marks a task that is not the user's own.
            ->assertSee(__('workspaces.my_work.owner', ['name' => 'Bob Builder']))
            ->assertDontSee('Bobs alone')
            ->assertDontSee('Left behind');

        Livewire::actingAs($this->other)->test(MyWork::class)
            ->assertSee('Bobs alone')
            ->assertDontSee('Mine');
    }

    public function test_rows_link_to_the_task_its_ticket_and_its_pull_request(): void
    {
        $task = $this->task('Fix checkout', [
            'status' => TaskStatus::PrOpen,
            'pr_number' => 42,
            'pr_state' => 'draft',
            'pr_url' => 'https://gitlab.com/o/r/-/merge_requests/42',
            'external_ref' => ['provider' => 'gitlab', 'number' => '17', 'title' => 'Checkout breaks', 'url' => 'https://gitlab.com/o/r/-/issues/17'],
        ]);
        $this->task('Linear one', ['external_ref' => ['provider' => 'linear', 'key' => 'ENG-118', 'number' => 'abc', 'title' => 'Ship it', 'url' => 'https://linear.app/x/issue/ENG-118']]);

        Livewire::actingAs($this->me)->test(MyWork::class)
            ->assertSee($task->url(), false)
            ->assertSee('Shop API')
            ->assertSee('GitLab #17')
            ->assertSee('https://gitlab.com/o/r/-/issues/17', false)
            ->assertSee('#42 · '.__('workspaces.pr_state.draft'), false)
            ->assertSee('https://gitlab.com/o/r/-/merge_requests/42', false)
            ->assertSee('ENG-118')
            ->assertSee(__('enums.task_state.pr_open'));
    }

    public function test_empty_state_points_members_to_the_workspaces_and_admins_to_creating_one(): void
    {
        AgentCredential::factory()->ownedBy($this->me)->create();
        Livewire::actingAs($this->me)->test(MyWork::class)
            ->assertSee(__('workspaces.my_work.empty_title'))
            ->assertSee(WorkspaceResource::getUrl('index', panel: 'app'), false)
            ->assertDontSee(WorkspaceResource::getUrl('create', panel: 'app'), false);

        $admin = User::factory()->admin()->create();
        AgentCredential::factory()->ownedBy($admin)->create();
        Livewire::actingAs($admin)->test(MyWork::class)
            ->assertSee(__('workspaces.my_work.empty_title'))
            ->assertSee(WorkspaceResource::getUrl('create', panel: 'app'), false);
    }

    public function test_a_new_person_sees_the_first_steps(): void
    {
        $newcomer = User::factory()->create(['locale' => 'en']);
        User::factory()->admin()->create(['name' => 'Grace Admin']);

        Livewire::actingAs($newcomer)->test(MyWork::class)
            ->assertSeeHtml('data-testid="first-steps"')
            ->assertSee(__('workspaces.my_work.steps.agent_action'))
            ->assertSee(__('workspaces.my_work.steps.workspace_hint_named', ['names' => 'Grace Admin']));

        // With a subscription and a workspace there is nothing left to set up.
        AgentCredential::factory()->ownedBy($this->me)->create();
        Livewire::actingAs($this->me)->test(MyWork::class)->assertDontSeeHtml('data-testid="first-steps"');
    }

    public function test_the_workspace_list_tells_a_member_without_workspace_whom_to_ask(): void
    {
        Livewire::actingAs(User::factory()->create(['locale' => 'en']))
            ->test(ListWorkspaces::class)
            ->assertSee(__('workspaces.empty.member_heading'));
    }

    public function test_page_is_live(): void
    {
        $this->actingAs($this->me)->get(MyWork::getUrl(panel: 'app'))
            ->assertSee('wire:poll.30s', false)
            ->assertSee('x-on:argos-workspace-signal.window', false);
    }
}
