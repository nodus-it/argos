<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\GateRunStatus;
use App\Enums\ReviewCommentStatus;
use App\Enums\TaskEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\ChangeTray;
use App\Livewire\Workspace\ReviewPanel;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\ReviewComment;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Support\GateAnnotations;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Line comments collected in the review and sent to Claude as one message. */
class ReviewCommentsTest extends TestCase
{
    use RefreshDatabase;

    private const DIFF = "diff --git a/resources/views/welcome.blade.php b/resources/views/welcome.blade.php\n--- a/resources/views/welcome.blade.php\n+++ b/resources/views/welcome.blade.php\n@@ -1,4 +1,4 @@\n <body>\n-  <a href=\"/dashboard\">Dashboard</a>\n+  <a href=\"{{ route('login') }}\" class=\"btn\">Login</a>\n   <nav></nav>\n </body>\n";

    private Workspace $workspace;

    private User $editor;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('app');
        Event::fake([TaskStream::class, UserWorkspaceSignal::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        FakeWorkspaceDaemon::$diff = self::DIFF;
        FakeWorkspaceDaemon::$fileContents = ['resources/views/welcome.blade.php' => "<body>\n  <a href=\"{{ route('login') }}\" class=\"btn\">Login</a>\n  <nav></nav>\n</body>\n"];
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->editor = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Member]);
        AgentCredential::factory()->ownedBy($this->editor)->create();
        $this->task = Task::factory()->open()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id, 'agent_credential_id' => AgentCredential::query()->first()->id]);
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$diff = null;
        FakeWorkspaceDaemon::$fileContents = [];
        parent::tearDown();
    }

    private function review(?User $user = null): Testable
    {
        return Livewire::withQueryParams(['task' => $this->task->id, 'review' => '1'])
            ->actingAs($user ?? $this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id]);
    }

    public function test_a_comment_is_collected_not_sent_and_shown_inline(): void
    {
        $this->review()
            ->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'Reuse the nav partial')
            ->assertDispatched('review-comments-changed')
            ->assertSee('Reuse the nav partial')
            ->assertSeeHtml('class="ag-dbadge"');

        $comment = ReviewComment::query()->sole();
        $this->assertSame(ReviewCommentStatus::Draft, $comment->status);
        $this->assertStringContainsString('route(\'login\')', $comment->snippet);
        $this->assertStringContainsString('<body>', $comment->snippet);
        $this->assertSame(0, TaskEvent::query()->where('type', TaskEventType::UserMessage->value)->count());
    }

    public function test_send_review_sends_one_structured_message_with_the_free_text(): void
    {
        $this->review()
            ->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'Reuse the nav partial')
            ->call('addComment', 'resources/views/welcome.blade.php', 'old', 2, 2, 'Keep the dashboard link');

        Livewire::withQueryParams(['task' => $this->task->id])->actingAs($this->editor)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(__('workspaces.review.send_review'))
            ->assertSee('welcome.blade.php:2')
            ->set('draft', 'Also make the button smaller.')
            ->call('send')
            ->assertDispatched('review-comments-changed');

        $message = TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::UserMessage->value)->sole()->payload['text'];
        $this->assertStringStartsWith(__('workspaces.review.message_heading')."\n1. resources/views/welcome.blade.php:2", $message);
        $this->assertStringContainsString("```blade\n", $message);
        $this->assertStringContainsString('→ Reuse the nav partial', $message);
        $this->assertStringContainsString('2. resources/views/welcome.blade.php:2 '.__('workspaces.review.removed_line'), $message);
        $this->assertStringEndsWith("\n\nAlso make the button smaller.", $message);
        $this->assertSame(2, ReviewComment::query()->where('status', ReviewCommentStatus::Sent->value)->count());

        // The chat shows it as a review card; Claude got the text above.
        $payload = TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::UserMessage->value)->sole()->payload;
        $this->assertSame(['Reuse the nav partial', 'Keep the dashboard link'], array_column($payload['review']['comments'], 'body'));
        $this->assertSame('Also make the button smaller.', $payload['review']['text']);
        Livewire::withQueryParams(['task' => $this->task->id])->actingAs($this->editor)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(trans_choice('workspaces.review.message_card', 2, ['count' => 2]))
            ->assertSeeHtml('class="ag-review-msg-item"');
    }

    public function test_the_chip_removes_a_comment_and_drafts_survive_a_reload(): void
    {
        $this->review()->call('addComment', 'resources/views/welcome.blade.php', 'new', 1, 2, 'Range comment');
        $id = ReviewComment::query()->sole()->id;

        // A fresh component (reload) still has the draft.
        Livewire::withQueryParams(['task' => $this->task->id])->actingAs($this->editor)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('welcome.blade.php:1-2')
            ->call('removeComment', $id)
            ->assertDontSee('welcome.blade.php:1-2');

        $this->assertSame(ReviewCommentStatus::Discarded, ReviewComment::query()->sole()->status);
    }

    public function test_viewers_cannot_comment(): void
    {
        $viewer = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);

        $this->review($viewer)
            ->assertDontSeeHtml('ag-dplus')
            ->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'x');
        $this->assertSame(0, ReviewComment::query()->count());
    }

    public function test_only_people_on_the_task_comment_collaborators_too(): void
    {
        $member = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);

        $this->review($member)->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'x');
        $this->assertSame(0, ReviewComment::query()->count());

        $this->task->members()->create(['user_id' => $member->id, 'invited_by' => $this->editor->id]);
        $this->review($member)->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'From a collaborator');
        $this->assertSame($member->id, ReviewComment::query()->sole()->user_id);
    }

    public function test_the_message_marks_stale_comments_as_the_previous_version(): void
    {
        $stale = ReviewComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->editor->id, 'status' => ReviewCommentStatus::Stale, 'path' => 'app/Foo.php', 'line_start' => 4, 'line_end' => 5, 'body' => 'Rename']);

        $message = ReviewCommentService::message(collect([$stale]), '');

        $this->assertStringContainsString('1. app/Foo.php:4-5 '.__('workspaces.review.previous_version'), $message);
        $this->assertStringContainsString("```php\n", $message);
    }

    public function test_comments_follow_moved_lines_and_turn_stale_when_changed(): void
    {
        $content = "<?php\nRoute::view('/', 'welcome');\nRoute::view('/login', 'login');\n";
        $lines = ReviewCommentService::numbered($content);
        $moved = app(ReviewCommentService::class)->add($this->task, $this->editor, 'routes/web.php', 'new', 3, 3, 'Name it', $lines);
        $changed = app(ReviewCommentService::class)->add($this->task, $this->editor, 'routes/web.php', 'new', 2, 2, 'Keep it', $lines);

        // Claude inserted a line on top and rewrote the welcome route.
        $now = "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::view('/', 'home');\nRoute::view('/login', 'login');\n";
        $this->assertSame(2, app(ReviewCommentService::class)->reanchor($this->task, ['routes/web.php' => $now]));

        $this->assertSame([4, 4, ReviewCommentStatus::Draft], [$moved->fresh()->line_start, $moved->fresh()->line_end, $moved->fresh()->status]);
        $this->assertSame(ReviewCommentStatus::Stale, $changed->fresh()->status);
    }

    public function test_turn_completion_reanchors_through_the_daemon(): void
    {
        config(['queue.default' => 'sync']);
        $lines = ReviewCommentService::numbered("a\nb\nc\n");
        $comment = app(ReviewCommentService::class)->add($this->task, $this->editor, 'notes.txt', 'new', 2, 2, 'B?', $lines);
        FakeWorkspaceDaemon::$fileContents = ['notes.txt' => "a\nB changed\nc\n"];

        app(TaskEventIngestor::class)->ingest($this->workspace, [
            ['task_id' => $this->task->id, 'seq' => $this->task->last_seq + 1, 'type' => 'turn.completed', 'data' => ['session_cost_usd' => 0.01, 'duration_ms' => 1000]],
        ]);

        $this->assertSame(ReviewCommentStatus::Stale, $comment->fresh()->status);
        FakeWorkspaceDaemon::$fileContents = [];
    }

    public function test_outdated_comments_send_anyway_or_move_to_the_new_line(): void
    {
        $stale = ReviewComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->editor->id, 'path' => 'resources/views/welcome.blade.php', 'line_start' => 2, 'line_end' => 2, 'status' => ReviewCommentStatus::Stale, 'body' => 'Old remark']);
        $other = ReviewComment::factory()->create(['task_id' => $this->task->id, 'user_id' => $this->editor->id, 'path' => 'resources/views/welcome.blade.php', 'line_start' => 3, 'line_end' => 3, 'status' => ReviewCommentStatus::Stale]);
        FakeWorkspaceDaemon::$fileContents = ['resources/views/welcome.blade.php' => "<body>\n  <a class=\"btn\">Login</a>\n  <nav></nav>\n</body>\n"];

        $this->review()
            ->assertSee(__('workspaces.review.stale_hint'))
            ->call('sendAnyway', $stale->id)
            ->call('moveComment', $other->id);

        $this->assertSame([ReviewCommentStatus::Draft, true], [$stale->fresh()->status, $stale->fresh()->outdated]);
        $this->assertSame([ReviewCommentStatus::Draft, false], [$other->fresh()->status, $other->fresh()->outdated]);
        $this->assertStringContainsString('<nav></nav>', $other->fresh()->snippet);

        app(ReviewCommentService::class)->send($this->task, $this->editor, '');
        $message = TaskEvent::query()->where('type', TaskEventType::UserMessage->value)->sole()->payload['text'];
        $this->assertStringContainsString(':2 '.__('workspaces.review.previous_version'), $message);
        FakeWorkspaceDaemon::$fileContents = [];
    }

    public function test_phpstan_findings_annotate_the_diff_and_go_into_the_collection(): void
    {
        WorkspaceGateRun::factory()->create([
            'task_id' => $this->task->id,
            'status' => GateRunStatus::Failed,
            'gates' => ['phpstan' => 'fail', 'pint' => 'pass'],
            'logs' => ['phpstan' => "PHPStan — new errors vs. baseline (these block the gate):\n\nresources/views/welcome.blade.php:2: Undefined variable: \$features\n"],
        ]);

        $annotations = GateAnnotations::fromRun(WorkspaceGateRun::query()->sole());
        $this->assertSame(['resources/views/welcome.blade.php' => [['line' => 2, 'message' => 'Undefined variable: $features']]], $annotations['phpstan']);
        $this->assertSame(__('workspaces.tray.gates_failed', ['gates' => trans_choice('workspaces.tray.gate_errors', 1, ['gate' => 'PHPStan', 'count' => 1])]), ChangeTray::gateSummary(WorkspaceGateRun::query()->sole()));

        $this->review()
            ->assertSee('Undefined variable: $features')
            ->assertSee('! PHPStan 1')
            ->call('fixWithClaude', 'resources/views/welcome.blade.php', 2, 'PHPStan', 'Undefined variable: $features');

        $comment = ReviewComment::query()->sole();
        $this->assertSame([2, __('workspaces.review.fix_body', ['gate' => 'PHPStan', 'message' => 'Undefined variable: $features'])], [$comment->line_start, $comment->body]);
    }
}
