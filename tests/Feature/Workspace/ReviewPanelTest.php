<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\GitChanges;
use App\Integrations\WorkspaceDaemon\Requests\GitFetch;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Livewire\Workspace\ContextPanel;
use App\Livewire\Workspace\ReviewPanel;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Support\ReviewDiff;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([TaskStream::class]);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->editor = $this->member(WorkspaceRole::Member, 'Ada');
        $this->task = Task::factory()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id]);
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$diff = null;
        parent::tearDown();
    }

    public function test_the_review_is_wired_into_the_page(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertSeeLivewire(ContextPanel::class)
            ->assertSeeLivewire(ReviewPanel::class);
    }

    public function test_shows_the_diff_once_opened_split_or_unified(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        $review = Livewire::actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertDontSee('README.md')
            ->call('open')
            ->assertSee('README.md')
            ->assertSee('Hello Argos')
            ->assertSeeHtml('class="wf-drow"');

        $review->call('setView', 'unified')->assertSeeHtml('wf-urow');
        $this->assertSame('unified', $this->editor->fresh()->ui_prefs['diff_view']);
    }

    public function test_seen_resets_when_the_file_changes_again(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $review = Livewire::withQueryParams(['task' => $this->task->id, 'review' => '1'])->actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id]);
        $hash = $review->viewData('current')['hash'];
        $review->call('toggleSeen', 'README.md', $hash, true)
            ->assertSee(__('workspaces.review.seen_progress', ['seen' => 1, 'total' => 1]));

        FakeWorkspaceDaemon::$diff = "diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-Hello\n+Hello again\n";
        Cache::forget('ws-changes:'.$this->task->id);
        $review->call('$refresh')->assertSee(__('workspaces.review.seen_progress', ['seen' => 0, 'total' => 1]));
    }

    public function test_unchanged_lines_between_hunks_expand(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        FakeWorkspaceDaemon::$diff = "diff --git a/a.php b/a.php\n--- a/a.php\n+++ b/a.php\n@@ -10,3 +10,3 @@\n line 10\n-old\n+line 11\n line 12\n";

        Livewire::withQueryParams(['review' => '1'])->actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(trans_choice('workspaces.review.unchanged', 9, ['count' => 9]))
            ->call('expand', 'a.php', 0)
            ->assertSee('line 3')
            ->assertDontSee(trans_choice('workspaces.review.unchanged', 9, ['count' => 9]));
    }

    public function test_split_rows_pair_removed_and_added_lines(): void
    {
        $rows = ReviewDiff::split([
            ['type' => 'context', 'old_num' => 1, 'new_num' => 1, 'text' => 'a'],
            ['type' => 'del', 'old_num' => 2, 'new_num' => null, 'text' => 'b'],
            ['type' => 'del', 'old_num' => 3, 'new_num' => null, 'text' => 'c'],
            ['type' => 'add', 'old_num' => null, 'new_num' => 2, 'text' => 'B'],
        ]);

        $this->assertCount(3, $rows);
        $this->assertSame(['b', 'B'], [$rows[1]['left']['text'], $rows[1]['right']['text']]);
        $this->assertSame(['c', null], [$rows[2]['left']['text'], $rows[2]['right']]);
    }

    public function test_a_stopped_workspace_shows_a_hint(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();

        Livewire::actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->call('open')
            ->assertSee(__('workspaces.tabs.not_running'));
    }

    public function test_the_change_list_is_cheap_and_fetches_at_most_once_a_minute(): void
    {
        $fetches = 0;
        Saloon::fake([
            GitFetch::class => function () use (&$fetches) {
                $fetches++;

                return MockResponse::make([], 202);
            },
            GitStatus::class => MockResponse::make(['branch' => 'ws/x', 'upstream' => null, 'ahead' => 2, 'behind' => 0, 'dirty' => true, 'files' => [['path' => 'a', 'status' => 'M']]]),
            GitChanges::class => MockResponse::make(['files' => [['path' => 'a', 'from_path' => null, 'status' => 'M', 'additions' => 1, 'deletions' => 1, 'binary' => false, 'generated' => false, 'hash' => str_repeat('a', 40)]]]),
        ]);

        $first = app(WorkspaceDiffService::class)->summary($this->task);
        Cache::forget('ws-changes:'.$this->task->id);
        app(WorkspaceDiffService::class)->summary($this->task);
        // The fetch throttle is per workspace: another task reuses it.
        app(WorkspaceDiffService::class)->summary(Task::factory()->create(['workspace_id' => $this->workspace->id]));

        $this->assertSame(1, $fetches);
        $this->assertSame(2, $first['ahead']);
        $this->assertSame(1, $first['uncommitted']);
        $this->assertSame('a', $first['files'][0]['path']);
    }

    public function test_generated_files_and_big_diffs_load_on_request(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $lines = implode('', array_map(fn (int $n): string => "+line {$n}\n", range(1, 900)));
        FakeWorkspaceDaemon::$diff = "diff --git a/composer.lock b/composer.lock\n--- a/composer.lock\n+++ b/composer.lock\n@@ -1 +1 @@\n-{}\n+{ }\n"
            ."diff --git a/big.php b/big.php\nnew file mode 100644\n--- /dev/null\n+++ b/big.php\n@@ -0,0 +1,900 @@\n".$lines;

        Livewire::withQueryParams(['review' => '1'])->actingAs($this->editor)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('big.php')
            ->assertSee('line 400')
            ->assertDontSee('line 401')
            ->assertSeeHtml('wf-diff-more')
            ->call('showMoreLines', 'big.php')
            ->assertSee('line 900')
            ->call('selectFile', 'composer.lock')
            ->assertSee(__('workspaces.review.generated_hidden'))
            ->call('loadGeneratedDiff', 'composer.lock')
            ->assertDontSee(__('workspaces.review.generated_hidden'));
    }
}
