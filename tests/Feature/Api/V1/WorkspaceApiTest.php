<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Broadcasting\TaskStream;
use App\Enums\AutoTaskState;
use App\Enums\TaskOrigin;
use App\Enums\TaskProviderKind;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Jobs\SendTicketWebhookJob;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Jobs\Workspace\SetUpWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\ApiClient;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $bot;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoTaskJob::class]);
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->bot = User::factory()->create(['name' => 'CI Bot']);
        AgentCredential::factory()->ownedBy($this->bot)->create();
        $this->workspace = Workspace::factory()->create(['name' => 'widget']);
        $this->join($this->workspace, $this->bot);
    }

    private function join(Workspace $workspace, User $user, WorkspaceRole $role = WorkspaceRole::Member): void
    {
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);
    }

    /** @param  list<string>  $abilities */
    private function clientToken(array $abilities = ['workspaces:read', 'tasks:read', 'tasks:write'], ?User $runAs = null): string
    {
        return ApiClient::factory()->create(['run_as_user_id' => ($runAs ?? $this->bot)->id])->createToken('t', $abilities)->plainTextToken;
    }

    public function test_it_requires_a_token_with_the_ability(): void
    {
        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
        $this->withToken($this->clientToken(['tasks:read']))->getJson('/api/v1/workspaces')->assertForbidden();
        $this->withToken($this->clientToken(['tasks:read']))->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'x'])->assertForbidden();
    }

    public function test_a_client_without_a_run_as_user_is_refused(): void
    {
        $token = ApiClient::factory()->create()->createToken('t', ['tasks:write'])->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'Do it'])
            ->assertStatus(422)->assertJsonPath('code', 'no_actor');
    }

    public function test_it_starts_an_automatic_task_in_the_workspace(): void
    {
        $response = $this->withToken($this->clientToken())->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", [
            'prompt' => 'Add priorities to notes', 'title' => 'Priorities', 'plan' => 'approve', 'ship' => 'draft_pr', 'max_turns' => 20,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.origin', 'api')
            ->assertJsonPath('data.title', 'Priorities')
            ->assertJsonPath('data.workspace_id', $this->workspace->id)
            ->assertJsonPath('data.owner.name', 'CI Bot')
            ->assertJsonPath('data.branch', 'argos/priorities')
            ->assertJsonPath('data.auto.state', 'queued')
            ->assertJsonPath('data.auto.plan', 'approve');

        // No new workspace: the task runs in the existing one.
        $this->assertSame(1, Workspace::query()->count());
        $task = Task::query()->sole();
        $this->assertSame(TaskOrigin::Api, $task->origin);
        $this->assertSame($this->bot->id, $task->owner_user_id);
        $this->assertSame('draft_pr', $task->auto_config['ship']);
        $this->assertSame(20, $task->auto_config['max_turns']);
        Bus::assertDispatched(AdvanceAutoTaskJob::class, fn ($job): bool => $job->step === 'begin');
    }

    public function test_an_own_system_starts_tasks_with_its_ticket_and_reports_comments(): void
    {
        TaskProviderBinding::factory()->create(['workspace_id' => $this->workspace->id, 'kind' => TaskProviderKind::Custom, 'external_project_ref' => 'Support desk', 'callback_url' => 'https://desk.example.com/argos', 'webhook_secret' => 's']);
        Bus::fake([SendTicketWebhookJob::class]);

        $this->withToken($this->clientToken())->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", [
            'prompt' => 'Fix the printer', 'ticket' => ['key' => 'SUP-5', 'title' => 'Printer offline', 'url' => 'https://desk.example.com/5', 'body' => 'It is offline.'],
        ])->assertStatus(202)
            ->assertJsonPath('data.ticket.key', 'SUP-5')
            ->assertJsonPath('data.branch', 'sup-5-printer-offline');
        Bus::assertDispatched(SendTicketWebhookJob::class, fn (SendTicketWebhookJob $job): bool => $job->payload['event'] === 'started');

        $task = Task::query()->sole();
        $this->withToken($this->clientToken())->postJson("/api/v1/tasks/{$task->id}/ticket-comments", ['author' => 'Carol', 'body' => 'Also the scanner.'])
            ->assertStatus(202);
        $this->assertTrue($task->events()->get()->contains(fn ($e): bool => ($e->payload['kind'] ?? null) === 'ticket_comment' && $e->payload['body'] === 'Also the scanner.'));
    }

    public function test_a_ticket_needs_an_own_system_integration(): void
    {
        $this->withToken($this->clientToken())->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", [
            'prompt' => 'x', 'ticket' => ['key' => 'SUP-1', 'title' => 'T'],
        ])->assertStatus(422)->assertJsonPath('code', 'no_custom_binding');
    }

    public function test_a_viewer_cannot_start_tasks(): void
    {
        $viewer = User::factory()->create();
        AgentCredential::factory()->ownedBy($viewer)->create();
        $this->join($this->workspace, $viewer, WorkspaceRole::Viewer);

        $this->withToken($this->clientToken(runAs: $viewer))->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'x'])
            ->assertForbidden();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_person_without_an_agent_gets_a_clear_error(): void
    {
        $nobody = User::factory()->create();
        $this->join($this->workspace, $nobody);

        $this->withToken($this->clientToken(runAs: $nobody))->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'no_usable_agent');
        $this->assertSame(0, Task::query()->count());
    }

    public function test_workspace_tokens_act_for_the_automation_user_and_stay_in_their_workspace(): void
    {
        $this->workspace->forceFill(['automation_user_id' => $this->bot->id])->save();
        $other = Workspace::factory()->create(['name' => 'other']);
        $this->join($other, $this->bot, WorkspaceRole::Admin);
        $token = $this->workspace->createToken('p', ['workspaces:read', 'tasks:read', 'tasks:write'])->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'x'])->assertStatus(202)
            ->assertJsonPath('data.owner.name', 'CI Bot');
        $this->withToken($token)->postJson("/api/v1/workspaces/{$other->id}/tasks", ['prompt' => 'x'])->assertNotFound();

        $foreignTask = Task::factory()->create(['workspace_id' => $other->id]);
        $this->withToken($token)->getJson('/api/v1/workspaces/'.$other->id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/tasks/'.$foreignTask->id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/workspaces')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->workspace->id);
    }

    public function test_listing_shows_only_workspaces_of_the_acting_person(): void
    {
        $theirs = Workspace::factory()->create(['name' => 'theirs']);

        $token = $this->clientToken();
        $this->withToken($token)->getJson('/api/v1/workspaces')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->workspace->id)
            ->assertJsonPath('data.0.repository', 'test-org/test-repo');
        $this->withToken($token)->getJson('/api/v1/workspaces/'.$theirs->id)->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/workspaces/{$theirs->id}/tasks")->assertNotFound();
    }

    public function test_every_member_sees_all_tasks_of_the_workspace(): void
    {
        $colleague = User::factory()->create();
        $theirs = Task::factory()->ownedBy($colleague)->create(['workspace_id' => $this->workspace->id, 'title' => 'Their task']);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'archived_at' => now()]);
        $token = $this->clientToken(['tasks:read']);

        $this->withToken($token)->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $theirs->id);
        $this->withToken($token)->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks?archived=1")->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("/api/v1/tasks/{$theirs->id}")->assertOk()
            ->assertJsonPath('data.title', 'Their task')
            ->assertJsonPath('data.owner.id', $colleague->id);
    }

    public function test_tasks_can_be_paused_resumed_and_plans_need_a_waiting_plan(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $task = Task::factory()->ownedBy($this->bot)->create(['workspace_id' => $this->workspace->id, 'auto_state' => AutoTaskState::Implementing, 'auto_config' => ['plan' => 'off'], 'auto_counters' => []]);
        $token = $this->clientToken();

        $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/pause")->assertOk()->assertJsonPath('data.auto.state', 'paused');
        $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/resume")->assertStatus(202)->assertJsonPath('data.auto.state', 'gating');
        $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/plan", ['decision' => 'approve'])
            ->assertStatus(422)->assertJsonPath('code', 'no_plan_waiting');
        $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/plan", ['decision' => 'maybe'])->assertStatus(422);
    }

    public function test_a_member_cannot_steer_someone_elses_task(): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'auto_state' => AutoTaskState::Implementing, 'auto_config' => ['plan' => 'off'], 'auto_counters' => []]);

        $this->withToken($this->clientToken())->postJson("/api/v1/tasks/{$task->id}/pause")
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertSame(AutoTaskState::Implementing, $task->fresh()->auto_state);
    }

    public function test_another_automatic_task_can_start_on_another_base_branch(): void
    {
        Task::factory()->ownedBy($this->bot)->create(['workspace_id' => $this->workspace->id]);

        $this->withToken($this->clientToken())->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'Second task', 'plan' => 'off', 'base_branch' => 'develop'])
            ->assertStatus(202)->assertJsonPath('data.auto.state', 'queued')->assertJsonPath('data.auto.plan', 'off')
            ->assertJsonPath('data.base_branch', 'develop');
        $this->assertSame(2, Task::query()->count());
    }

    public function test_an_admin_client_creates_a_workspace(): void
    {
        Bus::fake([SetUpWorkspaceJob::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $payload = ['name' => 'Shop', 'repo_url' => 'https://github.com/acme/shop', 'platform' => 'github', 'default_branch' => 'main', 'auth_method' => 'pat', 'token' => 'test-token'];

        $this->withToken($this->clientToken(['workspaces:write'], $admin))->postJson('/api/v1/workspaces', $payload)
            ->assertCreated()->assertJsonPath('data.name', 'Shop')->assertJsonPath('data.repository', 'acme/shop');
        Bus::assertDispatched(SetUpWorkspaceJob::class);

        // Not an Argos admin, a read-only token, a workspace's own token: no.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->clientToken(['workspaces:write']))->postJson('/api/v1/workspaces', $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->clientToken(['workspaces:read'], $admin))->postJson('/api/v1/workspaces', $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->workspace->createToken('ci', ['workspaces:write'])->plainTextToken)->postJson('/api/v1/workspaces', $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->clientToken(['workspaces:write'], $admin))->postJson('/api/v1/workspaces', ['name' => 'x'])->assertUnprocessable();
    }

    public function test_a_task_can_ask_for_auto_merge_and_reports_its_pull_request(): void
    {
        $response = $this->withToken($this->clientToken())->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks", ['prompt' => 'Fix the login', 'auto_merge' => true])
            ->assertStatus(202)->assertJsonPath('data.auto_merge', true)->assertJsonPath('data.kind', 'task');
        $task = Task::query()->findOrFail($response->json('data.id'));
        $task->forceFill(['pr_url' => 'https://x/1', 'pr_number' => 1, 'pr_state' => 'open', 'pr_checks' => ['state' => 'failure', 'url' => null, 'failed' => []], 'pr_review_state' => 'approved', 'pr_conflicts' => true])->save();

        $this->withToken($this->clientToken())->getJson("/api/v1/tasks/{$task->id}")
            ->assertJsonPath('data.pull_request.checks', 'failure')
            ->assertJsonPath('data.pull_request.review', 'approved')
            ->assertJsonPath('data.pull_request.conflicts', true);
    }

    public function test_the_api_is_rate_limited(): void
    {
        $token = $this->clientToken();
        for ($i = 0; $i < 120; $i++) {
            $this->withToken($token)->getJson('/api/v1/workspaces')->assertOk();
        }

        $this->withToken($token)->getJson('/api/v1/workspaces')->assertStatus(429);
    }
}
