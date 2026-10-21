<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\ReviewCommentStatus;
use App\Enums\ThreadEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\ReviewPanel;
use App\Livewire\Workspace\ThreadChat;
use App\Models\AgentCredential;
use App\Models\ReviewComment;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\WorkspaceDaemonClient;
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

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('workspaces');
        Event::fake([ThreadStream::class, UserWorkspaceSignal::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        FakeWorkspaceDaemon::$diff = self::DIFF;
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->editor = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Editor]);
        AgentCredential::factory()->ownedBy($this->editor)->create();
        $this->thread = Thread::factory()->open()->create(['workspace_id' => $this->workspace->id, 'agent_credential_id' => AgentCredential::query()->first()->id]);
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$diff = null;
        parent::tearDown();
    }

    private function review(?User $user = null): Testable
    {
        return Livewire::withQueryParams(['thread' => $this->thread->id, 'review' => '1'])
            ->actingAs($user ?? $this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id]);
    }

    public function test_a_comment_is_collected_not_sent_and_shown_inline(): void
    {
        $this->review()
            ->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'Reuse the nav partial')
            ->assertDispatched('review-comments-changed')
            ->assertSee('Reuse the nav partial')
            ->assertSeeHtml('class="wf-dbadge"');

        $comment = ReviewComment::query()->sole();
        $this->assertSame(ReviewCommentStatus::Draft, $comment->status);
        $this->assertStringContainsString('route(\'login\')', $comment->snippet);
        $this->assertStringContainsString('<body>', $comment->snippet);
        $this->assertSame(0, ThreadEvent::query()->where('type', ThreadEventType::UserMessage->value)->count());
    }

    public function test_send_review_sends_one_structured_message_with_the_free_text(): void
    {
        $this->review()
            ->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'Reuse the nav partial')
            ->call('addComment', 'resources/views/welcome.blade.php', 'old', 2, 2, 'Keep the dashboard link');

        Livewire::withQueryParams(['thread' => $this->thread->id])->actingAs($this->editor)
            ->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(__('workspaces.review.send_review'))
            ->assertSee('welcome.blade.php:2')
            ->set('draft', 'Also make the button smaller.')
            ->call('send')
            ->assertDispatched('review-comments-changed');

        $message = ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', ThreadEventType::UserMessage->value)->sole()->payload['text'];
        $this->assertStringStartsWith(__('workspaces.review.message_heading')."\n1. resources/views/welcome.blade.php:2", $message);
        $this->assertStringContainsString("```blade\n", $message);
        $this->assertStringContainsString('→ Reuse the nav partial', $message);
        $this->assertStringContainsString('2. resources/views/welcome.blade.php:2 '.__('workspaces.review.removed_line'), $message);
        $this->assertStringEndsWith("\n\nAlso make the button smaller.", $message);
        $this->assertSame(2, ReviewComment::query()->where('status', ReviewCommentStatus::Sent->value)->count());
    }

    public function test_the_chip_removes_a_comment_and_drafts_survive_a_reload(): void
    {
        $this->review()->call('addComment', 'resources/views/welcome.blade.php', 'new', 1, 2, 'Range comment');
        $id = ReviewComment::query()->sole()->id;

        // A fresh component (reload) still has the draft.
        Livewire::withQueryParams(['thread' => $this->thread->id])->actingAs($this->editor)
            ->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
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
            ->assertDontSeeHtml('wf-dplus')
            ->call('addComment', 'resources/views/welcome.blade.php', 'new', 2, 2, 'x');
        $this->assertSame(0, ReviewComment::query()->count());
    }

    public function test_the_message_marks_stale_comments_as_the_previous_version(): void
    {
        $stale = ReviewComment::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $this->editor->id, 'status' => ReviewCommentStatus::Stale, 'path' => 'app/Foo.php', 'line_start' => 4, 'line_end' => 5, 'body' => 'Rename']);

        $message = ReviewCommentService::message(collect([$stale]), '');

        $this->assertStringContainsString('1. app/Foo.php:4-5 '.__('workspaces.review.previous_version'), $message);
        $this->assertStringContainsString("```php\n", $message);
    }
}
