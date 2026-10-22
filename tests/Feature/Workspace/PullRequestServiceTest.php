<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\GitProvider;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\ContextPanel;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\GitProvider\PullRequestDetails;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PullRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Task $task;

    private User $owner;

    private GitServiceContract&MockInterface $git;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->workspace = Workspace::factory()->create(['repo_url' => 'https://github.com/acme/widget', 'platform' => GitProvider::GitHub, 'token' => 'x', 'status' => WorkspaceStatus::Running]);
        $this->owner = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Member]);
        AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat-ada']]);
        $this->task = Task::factory()->ownedBy($this->owner)->create([
            'workspace_id' => $this->workspace->id,
            'branch' => 'ws/login',
            'base_branch' => 'develop',
            'status' => TaskStatus::PrOpen,
            'pr_number' => 7,
            'pr_url' => 'https://github.com/acme/widget/pull/7',
            'pr_state' => 'open',
        ]);

        $this->git = Mockery::mock(GitServiceContract::class);
        $this->mock(GitServiceFactory::class)->shouldReceive('forWorkspace')->andReturn($this->git);

        $tasks = Mockery::mock(app(TaskService::class));
        $tasks->shouldReceive('send')->andReturnUsing(function (Task $task, User $by, string $text): TaskEvent {
            $this->sent[] = $text;

            return new TaskEvent;
        });
        $this->app->instance(TaskService::class, $tasks);
    }

    private function service(): PullRequestService
    {
        return app(PullRequestService::class);
    }

    /** @param  array<string, mixed>  $overrides */
    private function details(array $overrides = []): PullRequestDetails
    {
        return new PullRequestDetails(...[
            'state' => 'open',
            'url' => 'https://github.com/acme/widget/pull/7',
            'checks' => ['state' => 'success', 'url' => 'https://ci/7', 'failed' => []],
            ...$overrides,
        ]);
    }

    /** @return list<string> */
    private function notices(): array
    {
        return TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::Notice)->get()
            ->map(fn (TaskEvent $e): string => (string) ($e->payload['kind'] ?? ''))->all();
    }

    public function test_refresh_stores_checks_reviews_and_conflicts(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->once()->with('acme', 'widget', 7)->andReturn($this->details([
            'checks' => ['state' => 'failure', 'url' => 'https://ci/7', 'failed' => [['name' => 'tests', 'url' => null]]],
            'reviewState' => 'changes_requested',
            'conflicts' => true,
            'autoMerge' => true,
        ]));

        $this->service()->refresh($this->task);

        $task = $this->task->fresh();
        $this->assertSame('failure', $task->pr_checks['state']);
        $this->assertSame('changes_requested', $task->pr_review_state);
        $this->assertTrue($task->pr_conflicts);
        $this->assertTrue($task->auto_merge);
        $this->assertSame(['pr_checks_failed', 'pr_changes_requested'], $this->notices());
    }

    public function test_refresh_announces_a_change_only_once(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->twice()->andReturn($this->details(['checks' => ['state' => 'failure', 'url' => null, 'failed' => []]]));

        $this->service()->refresh($this->task);
        $this->service()->refresh($this->task->fresh());

        $this->assertSame(['pr_checks_failed'], $this->notices());
    }

    public function test_a_green_pipeline_after_a_red_one_is_announced(): void
    {
        $this->task->forceFill(['pr_checks' => ['state' => 'failure', 'url' => null, 'failed' => []]])->save();
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn($this->details());

        $this->service()->refresh($this->task);

        $this->assertSame(['pr_checks_passed'], $this->notices());
    }

    public function test_a_merge_finishes_the_task(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn($this->details(['state' => 'merged']));

        $this->service()->refresh($this->task);

        $task = $this->task->fresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertContains('pr_merged', $this->notices());
    }

    public function test_a_failed_refresh_keeps_the_old_state(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->once()->andThrow(new \RuntimeException('boom'));

        $this->assertNull($this->service()->refresh($this->task));
        $this->assertSame('open', $this->task->fresh()->pr_state);
    }

    public function test_mark_ready_takes_the_draft_out_of_draft(): void
    {
        $this->task->forceFill(['pr_state' => 'draft'])->save();
        $this->git->shouldReceive('markPullRequestReady')->once()->with('acme', 'widget', 7);
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn($this->details());

        $this->service()->markReady($this->task, $this->owner);

        $this->assertSame('open', $this->task->fresh()->pr_state);
        $this->assertContains('pr_ready', $this->notices());
    }

    public function test_auto_merge_switches_on_at_the_provider(): void
    {
        $this->git->shouldReceive('enableAutoMerge')->once()->with('acme', 'widget', 7)->andReturn(true);

        $this->service()->setAutoMerge($this->task, $this->owner, true);

        $this->assertTrue($this->task->fresh()->auto_merge);
        $this->assertSame(['auto_merge_on'], $this->notices());
    }

    public function test_auto_merge_says_when_the_repository_does_not_allow_it(): void
    {
        $this->git->shouldReceive('enableAutoMerge')->once()->andReturn(false);

        try {
            $this->service()->setAutoMerge($this->task, $this->owner, true);
            $this->fail('Expected a WorkspaceException.');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.pr.auto_merge_unavailable'), $e->getMessage());
        }
        $this->assertFalse($this->task->fresh()->auto_merge);
    }

    public function test_auto_merge_without_a_pull_request_is_remembered(): void
    {
        $this->task->forceFill(['pr_number' => null, 'pr_url' => null, 'pr_state' => null, 'status' => TaskStatus::Active])->save();

        $this->service()->setAutoMerge($this->task, $this->owner, true);

        $this->assertTrue($this->task->fresh()->auto_merge);
    }

    public function test_apply_auto_merge_falls_back_when_the_provider_refuses(): void
    {
        $this->task->forceFill(['auto_merge' => true])->save();
        $this->git->shouldReceive('enableAutoMerge')->once()->andReturn(false);

        $this->service()->applyAutoMerge($this->task);

        $this->assertFalse($this->task->fresh()->auto_merge);
        $this->assertSame(['auto_merge_unavailable'], $this->notices());
    }

    public function test_fix_checks_hands_the_log_to_claude(): void
    {
        $this->git->shouldReceive('failedCheckLog')->once()->andReturn("tests: 1 failed\nExpected true");

        $this->service()->fixChecks($this->task, $this->owner);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Expected true', $this->sent[0]);
    }

    public function test_fix_checks_without_failures_says_so(): void
    {
        $this->git->shouldReceive('failedCheckLog')->once()->andReturn('');

        $this->expectException(WorkspaceException::class);
        $this->service()->fixChecks($this->task, $this->owner);
    }

    public function test_send_review_hands_reviews_and_comments_to_claude(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn($this->details([
            'reviewState' => 'changes_requested',
            'reviews' => [['author' => 'grace', 'state' => 'changes_requested', 'body' => 'Please add a test.', 'submitted_at' => null]],
            'comments' => [['id' => '1', 'author' => 'grace', 'body' => 'Typo here', 'path' => 'app/Login.php', 'line' => 12, 'url' => null, 'created_at' => null]],
        ]));

        $this->service()->sendReview($this->task, $this->owner);

        $this->assertStringContainsString('Please add a test.', $this->sent[0]);
        $this->assertStringContainsString('`app/Login.php:12`: Typo here', $this->sent[0]);
    }

    public function test_the_context_panel_shows_the_pull_request(): void
    {
        $this->task->forceFill(['pr_state' => 'draft'])->save();
        $this->git->shouldReceive('pullRequestDetails')->andReturn($this->details([
            'state' => 'draft',
            'checks' => ['state' => 'failure', 'url' => 'https://ci/7', 'failed' => []],
            'conflicts' => true,
        ]));

        Livewire::actingAs($this->owner)
            ->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $this->task->id)
            ->assertSee(__('workspaces.pr.title'))
            ->assertSee(__('workspaces.pr.fix_checks'))
            ->assertSee(__('workspaces.pr.ready'))
            ->assertSee(__('workspaces.pr.rebase'))
            ->assertSee(__('workspaces.pr.conflicts', ['branch' => 'develop']));
    }

    public function test_the_context_panel_switches_auto_merge(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->andReturn($this->details());
        $this->git->shouldReceive('enableAutoMerge')->once()->andReturn(true);

        Livewire::actingAs($this->owner)
            ->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $this->task->id)
            ->call('prAutoMerge', true)
            ->assertNotNotified();

        $this->assertContains('auto_merge_on', $this->notices());
    }

    public function test_feedback_leaves_out_argos_own_comments(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->andReturn($this->details([
            'reviews' => [['author' => 'grace', 'state' => 'commented', 'body' => '', 'submitted_at' => null]],
            'comments' => [
                ['id' => '1', 'author' => 'grace', 'body' => 'Name can be hardcoded.', 'path' => 'resources/views/welcome.blade.php', 'line' => 7, 'url' => 'https://x/c1', 'created_at' => null],
                ['id' => '2', 'author' => 'bot', 'body' => "Neue Commits von Ada gepusht:\n\n- feat: x", 'path' => null, 'line' => null, 'url' => null, 'created_at' => null],
                ['id' => '3', 'author' => 'bot', 'body' => "New commits pushed by Ada:\n\n- feat: y\n\n".PullRequestService::ARGOS_MARKER, 'path' => null, 'line' => null, 'url' => null, 'created_at' => null],
            ],
        ]));

        $feedback = $this->service()->feedback($this->task);

        $this->assertSame([], $feedback['reviews']);
        $this->assertSame(['1'], array_column($feedback['comments'], 'id'));
    }

    public function test_reviewers_are_matched_to_argos_people(): void
    {
        $grace = User::factory()->create(['name' => 'Grace Hopper']);
        \App\Models\ConnectedAccount::factory()->create(['user_id' => $grace->id, 'provider' => 'github', 'nickname' => 'grace']);
        $this->git->shouldReceive('pullRequestDetails')->andReturn($this->details([
            'reviewState' => 'approved',
            'reviews' => [['author' => 'grace', 'avatar' => 'https://avatars.example/grace', 'state' => 'approved', 'body' => 'Looks good', 'submitted_at' => '2026-10-22T08:00:00Z']],
            'comments' => [['id' => '1', 'author' => 'octo', 'avatar' => 'https://avatars.example/octo', 'profile' => 'https://github.com/octo', 'body' => 'Typo', 'path' => 'a.php', 'line' => 3, 'url' => 'https://github.com/c/1', 'created_at' => '2026-10-22T08:05:00Z']],
        ]));

        $feedback = $this->service()->feedback($this->task);

        $this->assertTrue($feedback['reviewers'][0]['user']?->is($grace));
        $this->assertNull($feedback['comments'][0]['user']);

        Livewire::actingAs($this->owner)
            ->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $this->task->id)
            ->assertSee('Grace Hopper')
            ->assertSeeHtml('https://avatars.example/octo')
            // People link to their profile, times to the comment — in a new tab.
            ->assertSeeHtml('href="https://github.com/octo" target="_blank"')
            ->assertSeeHtml('href="https://github.com/c/1" target="_blank"')
            ->assertSee('GitHub');
    }

    public function test_the_sidebar_lists_the_review_comments(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->andReturn($this->details([
            'reviewState' => 'commented',
            'comments' => [['id' => '1', 'author' => 'grace', 'body' => 'Name can be hardcoded.', 'path' => 'resources/views/welcome.blade.php', 'line' => 7, 'url' => 'https://x/c1', 'created_at' => null]],
        ]));

        Livewire::actingAs($this->owner)
            ->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $this->task->id)
            ->assertSee('Name can be hardcoded.')
            ->assertSee('welcome.blade.php:7')
            ->assertSee(__('workspaces.review.collect_all'));
    }

    public function test_comments_are_collected_into_the_own_review_once(): void
    {
        $this->app->singleton(\App\Services\Workspace\WorkspaceDaemonClient::class, \App\Testing\FakeWorkspaceDaemon::class);
        \App\Testing\FakeWorkspaceDaemon::$fileContents = ['routes/web.php' => "<?php\n\nuse Route;\n\nRoute::view('/', 'welcome');\n"];
        $this->git->shouldReceive('pullRequestDetails')->andReturn($this->details([
            'comments' => [
                ['id' => 'c1', 'author' => 'grace', 'body' => 'Redirect home to /start', 'path' => 'routes/web.php', 'line' => 5, 'url' => 'https://x/c1', 'created_at' => null],
                ['id' => 'c2', 'author' => 'grace', 'body' => 'Looks good overall', 'path' => null, 'line' => null, 'url' => null, 'created_at' => null],
            ],
        ]));

        $result = $this->service()->collect($this->task, $this->owner);

        $this->assertSame(1, $result['collected']);
        $this->assertSame(['c2'], array_column($result['general'], 'id'));
        $draft = \App\Models\ReviewComment::query()->sole();
        $this->assertSame(['routes/web.php', 5, 'grace', 'c1'], [$draft->path, $draft->line_start, $draft->source_author, $draft->source_id]);
        $this->assertStringContainsString("Route::view('/', 'welcome');", $draft->snippet);
        $this->assertSame(['c1'], $this->service()->collectedIds($this->task, $this->owner));
        // Taken over once only.
        $this->assertSame(0, $this->service()->collect($this->task, $this->owner)['collected']);

        $message = \App\Services\Workspace\ReviewCommentService::message(collect([$draft]), '');
        $this->assertStringContainsString(__('workspaces.review.from_pr', ['name' => 'grace']).' Redirect home to /start', $message);
        $this->assertStringContainsString('grace', PullRequestService::generalText($result['general']));
    }

    public function test_details_are_cached_and_refreshed_in_the_background(): void
    {
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn($this->details(['reviewState' => 'approved']));

        $first = $this->service()->details($this->task);
        $second = $this->service()->details($this->task);
        $this->service()->feedback($this->task);

        // One provider call for all three; the cache holds plain arrays.
        $this->assertSame('approved', $first?->reviewState);
        $this->assertSame('approved', $second?->reviewState);
        $this->assertIsArray(\Illuminate\Support\Facades\Cache::get('task-pr:'.$this->task->id));
        $this->assertTrue($this->service()->hasDetails($this->task));
    }
}
