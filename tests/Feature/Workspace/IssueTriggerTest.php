<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\AutoThreadState;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\ExternalIssueLink;
use App\Models\RepoProfile;
use App\Models\TaskProviderBinding;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\IssueTracker\IssueIngestService;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\IssueTracker\PlanApprovalService;
use App\Services\Workspace\AutoThreadDriver;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class IssueTriggerTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    private IssueTrackerContract&MockInterface $tracker;

    /** @var list<string> */
    private array $comments = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ThreadStream::class]);
        Notification::fake();
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        $project = RepoProfile::factory()->create(['auto_settings' => ['plan' => 'approve']]);
        $bot = User::factory()->create(['name' => 'Issue Bot']);
        AgentCredential::factory()->ownedBy($bot)->create();
        $project->forceFill(['automation_user_id' => $bot->id])->save();
        $this->binding = TaskProviderBinding::factory()->create([
            'repo_profile_id' => $project->id, 'kind' => TaskProviderKind::GitHub, 'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active, 'external_project_ref' => 'acme/widget',
        ]);

        $this->tracker = Mockery::mock(IssueTrackerContract::class);
        $this->tracker->shouldReceive('createComment')->andReturnUsing(function ($owner, $project, $number, $body): array {
            $this->comments[] = $body;

            return ['id' => count($this->comments)];
        });
        $this->tracker->shouldReceive('commentId')->andReturnUsing(fn (array $r): string => (string) $r['id']);
        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('has')->andReturn(true);
        $registry->shouldReceive('make')->andReturn($this->tracker);
        $this->app->instance(IssueTrackerRegistry::class, $registry);
    }

    private function ingest(): ExternalIssueLink
    {
        return app(IssueIngestService::class)->ingest(ExternalIssue::fromProvider([
            'number' => 12, 'title' => 'Add priorities', 'body' => 'Notes need a priority.', 'html_url' => 'https://github.com/acme/widget/issues/12', 'state' => 'open', 'labels' => [],
        ], TaskProviderKind::GitHub), $this->binding);
    }

    /** Run the queued steps inline, with the workspace already up. */
    private function runInline(): void
    {
        config(['queue.default' => 'sync']);
        // Provisioning stays faked; the workspace is "up" from the start.
        Queue::fake([ProvisionWorkspaceJob::class, StopWorkspaceJob::class]);
    }

    public function test_an_issue_run_is_announced_and_its_plan_posted_on_the_issue(): void
    {
        $this->runInline();
        Workspace::creating(fn (Workspace $w) => $w->status = WorkspaceStatus::Running);

        $link = $this->ingest();

        $thread = Thread::query()->sole();
        $this->assertSame(AutoThreadState::AwaitingPlanApproval, $thread->auto_state);
        $this->assertStringContainsString('started working on this issue', $this->comments[0]);
        $this->assertStringContainsString('React with 👍', $this->comments[1]);
        $this->assertStringContainsString('Add the field', $this->comments[1]);
        $this->assertSame('2', $link->fresh()->plan_comment_id);
        $this->assertSame($thread->workspace_id, $link->fresh()->workspace_id);
    }

    public function test_a_thumbs_up_from_someone_with_write_access_approves_the_plan(): void
    {
        $this->runInline();
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        Workspace::creating(fn (Workspace $w) => $w->status = WorkspaceStatus::Running);
        $this->tracker->shouldReceive('createPullRequest')->never();
        $link = $this->ingest();

        $this->tracker->shouldReceive('getCommentReactions')->andReturn([
            ['emoji' => '+1', 'user_id' => '1', 'user_login' => 'stranger'],
            ['emoji' => '+1', 'user_id' => '2', 'user_login' => 'maintainer'],
        ]);
        $this->tracker->shouldReceive('userCanApprove')->andReturnUsing(fn ($o, $p, array $r): bool => $r['user_login'] === 'maintainer');
        $git = Mockery::mock(GitServiceContract::class);
        $git->shouldReceive('createPullRequest')->andReturn(['number' => 4, 'html_url' => 'https://x/4', 'state' => 'open']);
        $this->mock(GitServiceFactory::class)->shouldReceive('fromRepoProfile')->andReturn($git);

        $this->assertTrue(app(PlanApprovalService::class)->check($link->fresh()));

        $this->assertSame(AutoThreadState::Done, Thread::query()->sole()->auto_state);
        $this->assertFalse(app(PlanApprovalService::class)->check($link->fresh()), 'nothing waits any more');
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
    }

    public function test_reactions_without_write_access_do_nothing(): void
    {
        $this->runInline();
        Workspace::creating(fn (Workspace $w) => $w->status = WorkspaceStatus::Running);
        $link = $this->ingest();
        $this->tracker->shouldReceive('getCommentReactions')->andReturn([['emoji' => '+1', 'user_id' => '1', 'user_login' => 'stranger']]);
        $this->tracker->shouldReceive('userCanApprove')->andReturn(false);

        $this->assertFalse(app(PlanApprovalService::class)->check($link->fresh()));
        $this->assertSame(AutoThreadState::AwaitingPlanApproval, Thread::query()->sole()->auto_state);
    }

    public function test_a_failed_run_is_reported_on_the_issue(): void
    {
        $this->runInline();
        Workspace::creating(fn (Workspace $w) => $w->status = WorkspaceStatus::Running);
        $this->ingest();
        $thread = Thread::query()->sole();

        app(AutoThreadDriver::class)->onThreadEvents($thread, [['type' => 'error', 'data' => ['code' => 'session_failed', 'message' => 'claude crashed']]]);

        $this->assertStringContainsString('claude crashed', end($this->comments));
    }
}
