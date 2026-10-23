<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceIssueService;
use App\Support\UntrustedInput;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/** The task header (owner, collaborators, ticket, PR, actions) and the new-task form. */
class TaskHeaderTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $ada;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running, 'default_branch' => 'develop']);
        $this->ada = $this->member('Ada Lovelace');
        $this->bob = $this->member('Bob Builder');
        AgentCredential::factory()->ownedBy($this->ada)->create(['credentials' => ['token' => 'oat-a']]);
        AgentCredential::factory()->ownedBy($this->bob)->create(['credentials' => ['token' => 'oat-b']]);
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function task(array $attributes = []): Task
    {
        return Task::factory()->ownedBy($this->ada)->create([
            'workspace_id' => $this->workspace->id,
            'agent_credential_id' => $this->ada->agentCredentials()->first()->id,
            'title' => 'Login',
            ...$attributes,
        ]);
    }

    private function page(User $user, Task $task)
    {
        return Livewire::withQueryParams(['task' => $task->id])->actingAs($user)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()]);
    }

    public function test_the_header_shows_owner_collaborators_ticket_and_pull_request(): void
    {
        $task = $this->task([
            'external_ref' => ['provider' => 'gitlab', 'key' => 'gitlab-42', 'number' => '42', 'title' => 'Login page', 'url' => 'https://gitlab.com/acme/x/-/issues/42'],
            'status' => TaskStatus::PrOpen,
            'pr_url' => 'https://gitlab.com/acme/x/-/merge_requests/7',
            'pr_number' => 7,
            'pr_state' => 'draft',
        ]);
        $task->members()->create(['user_id' => $this->bob->id]);

        $this->page($this->bob, $task)
            ->assertSee(__('workspaces.switcher.owner', ['name' => 'Ada Lovelace']), false)
            ->assertSee(__('workspaces.task_actions.collaborator', ['name' => 'Bob Builder']), false)
            ->assertSee('GitLab #42')
            ->assertSee('https://gitlab.com/acme/x/-/issues/42', false)
            ->assertSee('#7 · '.__('workspaces.pr_state.draft'));
    }

    public function test_the_owner_invites_and_hands_over_from_the_header(): void
    {
        $task = $this->task();

        $this->page($this->ada, $task)
            ->callAction('invite', ['user_id' => $this->bob->id])
            ->assertNotified(__('workspaces.task_actions.invited', ['name' => 'Bob Builder']));
        $this->assertTrue($task->members()->where('user_id', $this->bob->id)->exists());

        $this->page($this->ada, $task)
            ->callAction('transfer', ['user_id' => $this->bob->id]);
        $this->assertSame($this->bob->id, $task->fresh()->owner_user_id);
    }

    public function test_others_cannot_invite_hand_over_cancel_or_delete(): void
    {
        $task = $this->task();

        $this->page($this->bob, $task)
            ->assertActionHidden('invite')
            ->assertActionHidden('transfer')
            ->assertActionHidden('cancelTask')
            ->assertActionHidden('deleteTask');
    }

    public function test_cancel_and_follow_up(): void
    {
        $task = $this->task();

        $this->page($this->ada, $task)
            ->assertActionHidden('followUp')
            ->callAction('cancelTask');
        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);

        $this->page($this->ada, $task->fresh())
            ->assertActionVisible('followUp')
            ->callAction('followUp')
            ->assertDispatched('task-follow-up', id: $task->id);
    }

    public function test_a_follow_up_is_created_from_the_chat_form_with_a_prefilled_brief(): void
    {
        $task = $this->task(['status' => TaskStatus::Done, 'base_branch' => 'release', 'pr_url' => 'https://x/pr/3', 'pr_state' => 'merged']);

        Livewire::actingAs($this->ada)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->dispatch('task-follow-up', id: $task->id)
            ->assertSet('creating', true)
            ->assertSet('newBaseBranch', 'release')
            ->assertSet('newTitle', __('workspaces.task.follow_up_title', ['title' => 'Login']))
            ->call('createTask')
            ->assertSet('creating', false)
            ->assertSet('draft', fn (string $draft): bool => str_contains($draft, 'https://x/pr/3'));

        $follow = Task::query()->where('parent_task_id', $task->id)->sole();
        $this->assertSame('release', $follow->base_branch);
        $this->assertSame($this->ada->id, $follow->owner_user_id);
    }

    public function test_the_new_task_form_links_a_ticket_and_a_base_branch(): void
    {
        $binding = TaskProviderBinding::factory()->create([
            'workspace_id' => $this->workspace->id,
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Poll,
            'external_project_ref' => 'acme/widget',
            'sync_status' => TaskProviderSyncStatus::Active,
        ]);
        $issues = Mockery::mock(WorkspaceIssueService::class, [app(IssueTrackerRegistry::class), app(UntrustedInput::class)])->makePartial();
        $issues->shouldReceive('bindingFor')->andReturn($binding);
        $issues->shouldReceive('search')->andReturn(['42' => '#42 Login page']);
        $issues->shouldReceive('load')->with(Mockery::type(Workspace::class), '42')->andReturn([
            'binding_id' => 'b', 'provider' => 'github', 'key' => 'github-42', 'owner' => 'acme', 'project' => 'widget',
            'number' => '42', 'title' => 'Login page', 'url' => 'https://github.com/acme/widget/issues/42', 'body' => 'Please add a login page.',
        ]);
        $this->app->instance(WorkspaceIssueService::class, $issues);

        Livewire::actingAs($this->ada)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->call('startCreating')
            ->set('newIssueQuery', 'login')
            ->assertSee('#42 Login page')
            ->call('chooseIssue', '42')
            ->assertSet('newTitle', 'Login page')
            ->set('newBaseBranch', 'develop')
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('42', $task->external_ref['number']);
        $this->assertSame('github-42-login-page', $task->branch);
        $this->assertSame('develop', $task->base_branch);
    }

    public function test_messages_of_other_people_carry_their_name(): void
    {
        $task = $this->task();
        $task->members()->create(['user_id' => $this->bob->id]);
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => 'user_message', 'author_user_id' => $this->bob->id, 'payload' => ['text' => 'From Bob', 'author_name' => 'Bob Builder']]);

        Livewire::withQueryParams(['task' => $task->id])->actingAs($this->ada)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->assertSeeHtml('is-other')
            ->assertSee('Bob Builder')
            ->assertSee('From Bob');
    }
}
