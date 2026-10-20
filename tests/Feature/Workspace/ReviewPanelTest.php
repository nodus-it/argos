<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\ThreadEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\GitDiff;
use App\Integrations\WorkspaceDaemon\Requests\GitFetch;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Livewire\Workspace\ReviewPanel;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class ReviewPanelTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([ThreadStream::class]);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->editor = $this->member(WorkspaceRole::Editor, 'Ada');
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    public function test_the_review_tab_is_wired_into_the_page(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertSeeLivewire(ReviewPanel::class);
    }

    public function test_shows_the_diff_once_loaded(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        Livewire::actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertDontSee('README.md')
            ->call('load')
            ->assertSee('README.md')
            ->assertSee('Hello Argos')
            ->assertSee('ws-commentable');
    }

    public function test_comments_go_to_claude_as_one_message(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        AgentCredential::factory()->ownedBy($this->editor)->create();
        $thread = Thread::factory()->create(['workspace_id' => $this->workspace->id, 'agent_credential_id' => AgentCredential::query()->first()->id]);

        Livewire::actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->call('sendComments', [
                ['path' => 'app/Foo.php', 'line' => 42, 'text' => 'Use a constant here'],
                ['path' => 'README.md', 'line' => 1, 'text' => '  '],
                ['path' => 'README.md', 'line' => 3, 'text' => 'Typo'],
            ])
            ->assertReturned(true)
            ->assertNotified();

        $message = ThreadEvent::query()->where('thread_id', $thread->id)->where('type', ThreadEventType::UserMessage->value)->firstOrFail();
        $this->assertSame(__('workspaces.review.message_heading')."\n- app/Foo.php:42 — Use a constant here\n- README.md:3 — Typo", $message->payload['text']);
    }

    public function test_viewers_cannot_comment(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Thread::factory()->create(['workspace_id' => $this->workspace->id]);
        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');

        Livewire::actingAs($viewer)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->call('load')
            ->assertDontSee('ws-commentable')
            ->call('sendComments', [['path' => 'a', 'line' => 1, 'text' => 'x']])
            ->assertReturned(false);
        $this->assertSame(0, ThreadEvent::query()->count());
    }

    public function test_a_stopped_workspace_shows_a_hint(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();

        Livewire::actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->call('load')
            ->assertSee(__('workspaces.tabs.not_running'));
    }

    public function test_workspace_diffs_fetch_at_most_once_a_minute(): void
    {
        $fetches = 0;
        Saloon::fake([
            GitFetch::class => function () use (&$fetches) {
                $fetches++;

                return MockResponse::make([], 202);
            },
            GitStatus::class => MockResponse::make(['branch' => 'ws/x', 'upstream' => null, 'ahead' => 2, 'behind' => 0, 'dirty' => true, 'files' => [['path' => 'a', 'status' => 'M']]]),
            GitDiff::class => MockResponse::make(['diff' => "diff --git a/a b/a\n--- a/a\n+++ b/a\n@@ -1 +1 @@\n-x\n+y\n", 'truncated' => false]),
        ]);

        $first = app(WorkspaceDiffService::class)->forWorkspace($this->workspace);
        app(WorkspaceDiffService::class)->forWorkspace($this->workspace);

        $this->assertSame(1, $fetches);
        $this->assertSame(2, $first['ahead']);
        $this->assertSame(1, $first['uncommitted']);
        $this->assertSame('a', $first['files'][0]['to_path']);
    }
}
