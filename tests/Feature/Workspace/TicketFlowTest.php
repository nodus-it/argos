<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\TaskEventType;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\TicketWebhook\Requests\PostTicketEvent;
use App\Jobs\SendTicketWebhookJob;
use App\Models\AgentCredential;
use App\Models\ConnectedAccount;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\IssueRunReporter;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\IssueTracker\TicketCommentSync;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

/**
 * A task's ticket hears about it (started, PR, done/cancelled), closes where
 * the integration wants it, follows a hand-over and talks back through new
 * comments; own systems get the same as a signed webhook.
 */
class TicketFlowTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $ada;

    private AgentCredential $agent;

    private TaskProviderBinding $binding;

    private MockInterface $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->ada = $this->member('Ada');
        $this->agent = AgentCredential::factory()->ownedBy($this->ada)->create(['credentials' => ['token' => 'oat']]);
        $this->tracker = Mockery::mock(IssueTrackerContract::class);
        $this->tracker->shouldReceive('createComment')->andReturn(['id' => 'c-1'])->byDefault();
        $this->tracker->shouldReceive('commentId')->andReturn('c-1')->byDefault();
        app(IssueTrackerRegistry::class)->register('linear', fn () => $this->tracker);
        app(IssueTrackerRegistry::class)->register('github', fn () => $this->tracker);
        $this->binding = $this->binding(TaskProviderKind::Linear, 'ENG');
    }

    private function member(string $name, WorkspaceRole $role = WorkspaceRole::Member): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => mb_strtolower($name).'@example.com']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function binding(TaskProviderKind $kind, string $ref, array $attributes = []): TaskProviderBinding
    {
        return TaskProviderBinding::factory()->create([
            'workspace_id' => $this->workspace->id,
            'kind' => $kind,
            'mode' => TaskProviderMode::Poll,
            'external_project_ref' => $ref,
            'sync_status' => TaskProviderSyncStatus::Active,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function ref(TaskProviderBinding $binding, string $number = 'uuid-118', string $key = 'ENG-118'): array
    {
        return ['binding_id' => $binding->id, 'provider' => $binding->kind->value, 'key' => $key, 'owner' => '', 'project' => (string) $binding->external_project_ref, 'number' => $number, 'title' => 'Login', 'url' => 'https://linear.app/x/issue/'.$key, 'body' => ''];
    }

    private function task(?array $ref = null): Task
    {
        return app(TaskService::class)->create($this->workspace, $this->ada, $this->agent, 'Login', options: ['external_ref' => $ref])->fresh();
    }

    public function test_a_task_with_a_ticket_says_it_started(): void
    {
        $this->tracker->shouldReceive('createComment')->once()->with('', 'ENG', 'uuid-118', Mockery::on(fn (string $body): bool => str_contains($body, $this->workspace->slug.'/')))->andReturn(['id' => 'c-1']);

        $this->task($this->ref($this->binding));
    }

    public function test_a_merged_task_closes_its_linear_ticket_when_the_integration_says_so(): void
    {
        $task = $this->task($this->ref($this->binding));
        $this->tracker->shouldReceive('closeIssue')->once()->with('', 'ENG', 'uuid-118');

        $task->forceFill(['status' => TaskStatus::Done, 'pr_url' => 'https://x/pr/1'])->save();
        app(IssueRunReporter::class)->closed($task);
    }

    public function test_closing_can_be_switched_off_and_github_closes_by_itself(): void
    {
        $this->binding->forceFill(['close_on_merge' => false])->save();
        $linear = $this->task($this->ref($this->binding));
        $github = $this->binding(TaskProviderKind::GitHub, 'acme/widget');
        $gh = $this->task(['provider' => 'github', 'binding_id' => $github->id, 'key' => 'github-7', 'owner' => 'acme', 'project' => 'widget', 'number' => '7', 'title' => 'X', 'url' => 'https://github.com/acme/widget/issues/7', 'body' => '']);
        $this->tracker->shouldNotReceive('closeIssue');

        foreach ([$linear, $gh] as $task) {
            $task->forceFill(['status' => TaskStatus::Done])->save();
            app(IssueRunReporter::class)->closed($task);
        }
    }

    public function test_a_cancelled_task_tells_its_ticket(): void
    {
        $task = $this->task($this->ref($this->binding));
        $this->tracker->shouldReceive('createComment')->once()->with('', 'ENG', 'uuid-118', Mockery::on(fn (string $body): bool => str_contains($body, __('workspaces.issue.run.task_cancelled', ['url' => $task->url()]))))->andReturn(['id' => 'c-2']);

        app(TaskService::class)->cancel($task, $this->ada);
    }

    public function test_handing_over_moves_the_ticket_assignee(): void
    {
        $bob = $this->member('Bob');
        AgentCredential::factory()->ownedBy($bob)->create(['credentials' => ['token' => 'oat-b']]);
        $task = $this->task($this->ref($this->binding));
        $this->tracker->shouldReceive('assignIssue')->once()->with('', 'ENG', 'uuid-118', 'bob@example.com')->andReturn(true);

        app(\App\Services\Workspace\TaskMembershipService::class)->transfer($task, $this->ada, $bob);
    }

    public function test_a_task_links_or_creates_its_ticket_afterwards(): void
    {
        $this->tracker->shouldReceive('getIssue')->andReturn(['id' => 'uuid-9', 'identifier' => 'ENG-9', 'title' => 'Billing', 'url' => 'https://linear.app/x/issue/ENG-9', 'description' => '']);
        $task = $this->task();

        app(\App\Services\Workspace\TaskTicketService::class)->linkTicket($task, $this->ada, 'uuid-9');
        $this->assertSame('ENG-9', $task->fresh()->external_ref['key']);

        $other = $this->task();
        $this->tracker->shouldReceive('createIssue')->once()->with(Mockery::any(), Mockery::any(), 'Dark mode', Mockery::on(fn (string $body): bool => str_contains($body, $other->url())))
            ->andReturn(['id' => 'uuid-10', 'identifier' => 'ENG-10', 'title' => 'Dark mode', 'url' => 'https://linear.app/x/issue/ENG-10', 'description' => '']);
        app(\App\Services\Workspace\TaskTicketService::class)->createTicket($other, $this->ada, 'Dark mode', 'Please.');
        $this->assertSame('ENG-10', $other->fresh()->external_ref['key']);
        $this->assertContains('ticket_created', TaskEvent::query()->where('task_id', $other->id)->where('type', TaskEventType::Notice->value)->get()->pluck('payload.kind')->all());
    }

    public function test_new_ticket_comments_become_hints_without_argos_own_ones(): void
    {
        $task = $this->task($this->ref($this->binding));
        $this->tracker->shouldReceive('listComments')->andReturn(
            [['id' => '1', 'author' => 'Carol', 'body' => 'Old', 'created_at' => null, 'url' => null]],
            [
                ['id' => '1', 'author' => 'Carol', 'body' => 'Old', 'created_at' => null, 'url' => null],
                ['id' => '2', 'author' => 'Argos', 'body' => 'Follow it live: '.$task->url(), 'created_at' => null, 'url' => null],
                ['id' => '3', 'author' => 'Carol', 'body' => 'Please also support SSO.', 'created_at' => null, 'url' => 'https://linear.app/c/3'],
            ],
        );

        $this->assertSame(0, app(TicketCommentSync::class)->sync($task), 'the first look only remembers');
        $this->assertSame(1, app(TicketCommentSync::class)->sync($task->fresh()));

        $hint = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::Notice->value)->get()->firstWhere('payload.kind', 'ticket_comment');
        $this->assertSame('Carol', $hint->payload['author_name']);
        $this->assertSame('Please also support SSO.', $hint->payload['body']);
        $this->assertSame('3', $task->fresh()->external_ref['last_comment_id']);
    }

    public function test_own_systems_hear_back_through_a_signed_webhook(): void
    {
        $custom = $this->binding(TaskProviderKind::Custom, 'Support desk', ['callback_url' => 'https://desk.example.com/argos', 'webhook_secret' => 's3cret']);
        Bus::fake([SendTicketWebhookJob::class]);

        $task = $this->task(['binding_id' => $custom->id, 'provider' => 'custom', 'key' => 'SUP-5', 'owner' => '', 'project' => 'Support desk', 'number' => 'SUP-5', 'title' => 'Printer', 'url' => 'https://desk.example.com/5', 'body' => '']);
        app(TaskService::class)->cancel($task, $this->ada);

        Bus::assertDispatched(SendTicketWebhookJob::class, fn (SendTicketWebhookJob $job): bool => $job->payload['event'] === 'started' && $job->payload['ticket']['key'] === 'SUP-5');
        Bus::assertDispatched(SendTicketWebhookJob::class, fn (SendTicketWebhookJob $job): bool => $job->payload['event'] === 'cancelled' && $job->payload['task']['status'] === 'cancelled');
    }

    public function test_the_webhook_is_signed_with_the_integration_secret(): void
    {
        $custom = $this->binding(TaskProviderKind::Custom, 'Support desk', ['callback_url' => 'https://desk.example.com/argos', 'webhook_secret' => 's3cret']);
        Saloon::fake([PostTicketEvent::class => MockResponse::make([], 200)]);

        (new SendTicketWebhookJob($custom->id, ['event' => 'done', 'task' => ['id' => 'x']]))->handle();

        Saloon::assertSent(function (PostTicketEvent $request, $response): bool {
            $body = (string) $request->body()->all();

            return $response->getPendingRequest()->getUrl() === 'https://desk.example.com/argos'
                && $request->headers()->get('X-Argos-Event') === 'done'
                && $request->headers()->get('X-Argos-Signature') === 'sha256='.hash_hmac('sha256', $body, 's3cret');
        });
    }

    public function test_the_issue_assignee_owns_the_auto_task_only_with_consent(): void
    {
        $github = $this->binding(TaskProviderKind::GitHub, 'acme/widget');
        $bot = $this->member('Bot');
        AgentCredential::factory()->ownedBy($bot)->create();
        $this->workspace->forceFill(['automation_user_id' => $bot->id])->save();
        $carol = $this->member('Carol');
        AgentCredential::factory()->ownedBy($carol)->create();
        ConnectedAccount::factory()->create(['user_id' => $carol->id, 'provider' => 'github', 'nickname' => 'carol-gh']);
        Bus::fake([\App\Jobs\Workspace\AdvanceAutoTaskJob::class, \App\Jobs\Workspace\PrepareTaskJob::class]);

        $ingest = fn (string $number) => app(\App\Services\IssueTracker\IssueIngestService::class)->ingest(
            new \App\Services\IssueTracker\DTO\ExternalIssue($number, 'Issue '.$number, 'Body', 'https://github.com/acme/widget/issues/'.$number, 'open', [], '', 'carol-gh'),
            $github,
        );

        $ingest('1');
        $first = Task::query()->where('external_ref->number', '1')->sole();
        $this->assertSame($bot->id, $first->owner_user_id, 'no consent: the automation user owns it');
        $this->assertTrue($first->members()->where('user_id', $carol->id)->exists(), 'the assignee is invited');

        $carol->forceFill(['auto_task_consent' => true])->save();
        $ingest('2');
        $this->assertSame($carol->id, Task::query()->where('external_ref->number', '2')->sole()->owner_user_id);
    }
}
