<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\GitProvider;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskProviderKind;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\GitChanges;
use App\Integrations\WorkspaceDaemon\Requests\GitCommit;
use App\Integrations\WorkspaceDaemon\Requests\GitDiff;
use App\Integrations\WorkspaceDaemon\Requests\GitPush;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Integrations\WorkspaceDaemon\Requests\RunOneshot;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\GitProvider\PullRequestDetails;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceShipService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class WorkspaceShipTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Task $task;

    private User $editor;

    private GitServiceContract&MockInterface $git;

    /** @var array<string, list<array<string, mixed>>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('app');
        Event::fake([TaskStream::class]);
        $this->workspace = Workspace::factory()->create(['repo_url' => 'https://gitlab.com/acme/sub/widget', 'platform' => GitProvider::GitLab, 'token' => 'glpat-x', 'status' => WorkspaceStatus::Running]);
        $this->editor = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Member]);
        $this->task = Task::factory()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id, 'branch' => 'ws/login', 'base_branch' => 'develop']);
        AgentCredential::factory()->ownedBy($this->editor)->create(['credentials' => ['token' => 'oat-ada']]);

        $this->git = Mockery::mock(GitServiceContract::class);
        $this->mock(GitServiceFactory::class)->shouldReceive('forWorkspace')->andReturn($this->git);
        $this->fakeDaemon();
    }

    /** @param array<class-string, MockResponse> $overrides */
    private function fakeDaemon(array $overrides = []): void
    {
        $defaults = [
            GitStatus::class => MockResponse::make(['branch' => 'ws/login', 'upstream' => null, 'ahead' => 0, 'behind' => 0, 'dirty' => true, 'files' => [['path' => 'a.php', 'status' => 'M']]]),
            GitDiff::class => MockResponse::make(['diff' => "diff --git a/a.php b/a.php\n+new\n", 'truncated' => false]),
            GitChanges::class => MockResponse::make(['files' => []]),
            RunOneshot::class => MockResponse::make(['text' => '', 'structured' => ['subject' => 'feat: add login', 'body' => 'Adds the login.'], 'cost_usd' => 0.001]),
            GitCommit::class => MockResponse::make(['sha' => str_repeat('b', 40)], 201),
            GitPush::class => MockResponse::make([], 202),
        ];
        $fakes = [];
        foreach (array_merge($defaults, $overrides) as $class => $response) {
            $fakes[$class] = function (PendingRequest $request) use ($class, $response) {
                $this->sent[class_basename($class)][] = method_exists($request->body() ?? new \stdClass, 'all') ? $request->body()->all() : [];

                return $response;
            };
        }
        Saloon::fake($fakes);
    }

    private function service(): WorkspaceShipService
    {
        return app(WorkspaceShipService::class);
    }

    public function test_draft_asks_claude_on_the_users_subscription(): void
    {
        $draft = $this->service()->draft($this->task, $this->editor);

        $this->assertSame(['subject' => 'feat: add login', 'body' => 'Adds the login.', 'changed_files' => 1, 'ahead' => 0], $draft);
        $prompt = $this->sent['RunOneshot'][0];
        $this->assertSame('oat-ada', $prompt['oauth_token']);
        $this->assertStringContainsString('+new', $prompt['user_prompt']);
        // Uncommitted changes: the message describes exactly what this commit takes.
        $this->assertStringContainsString('## git diff HEAD', $prompt['user_prompt']);
        $this->assertSame(['subject', 'body'], $prompt['json_schema']['required']);
    }

    public function test_the_pull_request_draft_covers_the_whole_branch(): void
    {
        $this->fakeDaemon([RunOneshot::class => MockResponse::make(['text' => '', 'structured' => ['title' => 'feat: add login', 'body' => "## What\n- Login"], 'cost_usd' => 0.001])]);

        $this->assertSame(['title' => 'feat: add login', 'body' => "## What\n- Login"], $this->service()->pullRequestDraft($this->task, $this->editor));
        $prompt = $this->sent['RunOneshot'][0];
        $this->assertStringContainsString('## git diff origin/develop', $prompt['user_prompt']);
        $this->assertSame(['title', 'body'], $prompt['json_schema']['required']);
        $this->assertStringContainsString('## Testing', $prompt['system_prompt']);
    }

    public function test_commit_and_push_are_separate_steps(): void
    {
        $sha = $this->service()->commit($this->task, $this->editor, 'feat: add login', 'Adds the login.');

        $this->assertSame(str_repeat('b', 40), $sha);
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $this->sent['GitCommit'][0]['author']);
        $this->assertArrayNotHasKey('GitPush', $this->sent);
        $notice = TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::Notice->value)->sole();
        $this->assertSame(['committed', 'feat: add login'], [$notice->payload['kind'], $notice->payload['subject']]);

        $this->fakeDaemon([GitStatus::class => MockResponse::make(['branch' => 'ws/login', 'upstream' => null, 'ahead' => 2, 'behind' => 0, 'dirty' => false, 'files' => []])]);
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body, $head, $base, $options) => $title === 'feat: login' && str_contains($body, '## What') && $options === ['draft' => false])
            ->andReturn(['iid' => 9, 'web_url' => 'https://x/9', 'state' => 'opened']);

        $this->assertSame('https://x/9', $this->service()->push($this->task, $this->editor, 'feat: login', "## What\n- Login"));
        $this->assertSame(1, count($this->sent['GitCommit']));
        $this->assertCount(1, $this->sent['GitPush']);
        $this->assertSame(9, $this->task->fresh()->pr_number);
    }

    public function test_no_commit_while_claude_is_mid_turn(): void
    {
        $this->task->forceFill(['session_open' => true, 'session_status' => SessionStatus::Running])->save();

        foreach ([fn () => $this->service()->commit($this->task, $this->editor, 'feat: x', ''), fn () => $this->service()->ship($this->task, $this->editor, 'feat: x', '')] as $call) {
            try {
                $call();
                $this->fail('expected WorkspaceException');
            } catch (WorkspaceException $e) {
                $this->assertSame(__('workspaces.ship.claude_working'), $e->getMessage());
            }
        }
        $this->assertArrayNotHasKey('GitCommit', $this->sent);
    }

    public function test_nothing_to_commit_and_nothing_to_push(): void
    {
        $this->fakeDaemon([GitCommit::class => MockResponse::make(['error' => ['code' => 'no_changes', 'message' => 'Nothing to commit']], 409)]);
        try {
            $this->service()->commit($this->task, $this->editor, 'feat: x', '');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.ship.nothing_to_commit'), $e->getMessage());
        }

        $this->task->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open'])->save();
        $this->expectExceptionMessage(__('workspaces.ship.nothing'));
        $this->service()->push($this->task, $this->editor);
    }

    public function test_draft_is_empty_without_an_agent_or_when_claude_fails(): void
    {
        AgentCredential::query()->delete();
        $this->assertSame('', $this->service()->draft($this->task, $this->editor)['subject']);

        AgentCredential::factory()->ownedBy($this->editor)->create();
        $this->fakeDaemon([RunOneshot::class => MockResponse::make(['error' => ['code' => 'oneshot_failed', 'message' => 'x']], 502)]);
        $this->assertSame('', $this->service()->draft($this->task, $this->editor)['subject']);
    }

    public function test_ship_commits_pushes_the_task_branch_and_opens_the_merge_request(): void
    {
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body, $head, $base, $options) => $owner === 'acme/sub' && $repo === 'widget' && $title === 'feat: add login'
                && str_contains($body, 'Adds the login.') && str_contains($body, $this->task->url())
                && str_contains($body, __('workspaces.ship.pr_footer', ['url' => $this->task->url(), 'name' => 'Ada']))
                && $head === 'ws/login' && $base === 'develop' && $options === ['draft' => true])
            ->andReturn(['iid' => 5, 'web_url' => 'https://gitlab.com/acme/sub/widget/-/merge_requests/5', 'state' => 'opened']);

        $url = $this->service()->ship($this->task, $this->editor, 'feat: add login', 'Adds the login.', true);

        $this->assertSame('https://gitlab.com/acme/sub/widget/-/merge_requests/5', $url);
        $this->task->refresh();
        $this->assertSame(5, $this->task->pr_number);
        $this->assertSame('https://gitlab.com/acme/sub/widget/-/merge_requests/5', $this->task->pr_url);
        $this->assertSame('draft', $this->task->pr_state);
        $this->assertSame(TaskStatus::PrOpen, $this->task->status);
        $this->assertNotNull($this->task->shipped_at);
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $this->sent['GitCommit'][0]['author']);
        $this->assertSame(['branch' => 'ws/login'], $this->sent['GitPush'][0]);
        $notice = TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::Notice->value)->firstOrFail();
        $this->assertSame('shipped', $notice->payload['kind']);
    }

    public function test_only_the_owner_and_collaborators_may_ship_a_task(): void
    {
        $other = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $other->id, 'role' => WorkspaceRole::Member]);

        $this->expectException(AuthorizationException::class);
        $this->service()->ship($this->task, $other, 'feat: x', '');
    }

    public function test_a_provider_failure_after_the_push_is_a_workspace_exception(): void
    {
        $this->git->shouldReceive('createPullRequest')->once()->andThrow(new \RuntimeException('422 head branch missing'));

        try {
            $this->service()->ship($this->task, $this->editor, 'feat: add login', '');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.ship.pr_failed', ['message' => '422 head branch missing']), $e->getMessage());
        }

        $this->assertCount(1, $this->sent['GitPush']);
        $this->task->refresh();
        $this->assertNull($this->task->pr_number);
        $this->assertNull($this->task->shipped_at);
        $this->assertSame(TaskStatus::Active, $this->task->status);
    }

    public function test_draft_is_empty_when_the_daemon_is_unreachable(): void
    {
        $this->fakeDaemon([GitStatus::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'down']], 500)]);

        $this->assertSame(['subject' => '', 'body' => '', 'changed_files' => 0, 'ahead' => 0], $this->service()->draft($this->task, $this->editor));
    }

    /** @return array<string, array{TaskProviderKind, bool, string}> */
    public static function issueReferences(): array
    {
        return [
            'gitlab closes' => [TaskProviderKind::GitLab, true, 'Closes #12'],
            'github closes' => [TaskProviderKind::GitHub, true, 'Closes #12'],
            'gitlab without close on merge' => [TaskProviderKind::GitLab, false, 'Refs #12'],
        ];
    }

    #[DataProvider('issueReferences')]
    public function test_shipping_an_issue_task_references_and_comments_on_the_issue(TaskProviderKind $kind, bool $closeOnMerge, string $reference): void
    {
        $binding = TaskProviderBinding::factory()->create(['workspace_id' => $this->workspace->id, 'kind' => $kind, 'close_on_merge' => $closeOnMerge]);
        $this->task->forceFill(['external_ref' => [
            'binding_id' => $binding->id, 'provider' => $kind->value, 'owner' => 'acme', 'project' => 'sub/widget', 'key' => '12',
            'number' => '12', 'title' => 'Login', 'url' => 'https://gitlab.com/acme/sub/widget/-/issues/12', 'body' => '',
        ]])->save();
        // The reference is real; only the tracker comment is faked.
        $this->partialMock(WorkspaceIssueService::class)
            ->shouldReceive('announcePullRequest')->once()->with(Mockery::on(fn ($task) => $task instanceof Task && $task->is($this->task)), 'https://x/9');
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body) => str_contains($body, "Adds the login.\n\n".$reference))
            ->andReturn(['iid' => 9, 'web_url' => 'https://x/9', 'state' => 'opened']);

        $this->service()->ship($this->task, $this->editor, 'feat: add login', 'Adds the login.');
    }

    public function test_shipping_again_comments_on_the_existing_pull_request(): void
    {
        $this->task->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open'])->save();
        $this->git->shouldReceive('createPullRequest')->never();
        $this->git->shouldReceive('commentOnPullRequest')->once()->withArgs(fn ($owner, $repo, $id, $body) => $id === 5 && str_contains($body, 'feat: more'));

        $this->assertSame('https://x/5', $this->service()->ship($this->task, $this->editor, 'feat: more', ''));
    }

    public function test_nothing_to_ship(): void
    {
        $this->fakeDaemon([
            GitCommit::class => MockResponse::make(['error' => ['code' => 'no_changes', 'message' => 'Nothing to commit']], 409),
            GitDiff::class => MockResponse::make(['diff' => '', 'truncated' => false]),
        ]);

        $this->expectException(WorkspaceException::class);
        $this->expectExceptionMessage(__('workspaces.ship.nothing'));
        $this->service()->ship($this->task, $this->editor, 'feat: x', '');
    }

    public function test_a_rejected_push_explains_how_to_continue(): void
    {
        $this->fakeDaemon([GitPush::class => MockResponse::make(['error' => ['code' => 'rejected_non_fast_forward', 'message' => 'rejected']], 409)]);

        $this->expectExceptionMessage(__('workspaces.ship.rejected'));
        $this->service()->ship($this->task, $this->editor, 'feat: x', '');
    }

    public function test_viewers_cannot_ship_and_a_subject_is_required(): void
    {
        try {
            $this->service()->ship($this->task, $this->editor, '  ', '');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertArrayNotHasKey('GitCommit', $this->sent);
        }

        $viewer = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $this->expectException(AuthorizationException::class);
        $this->service()->ship($this->task, $viewer, 'feat: x', '');
    }

    public function test_sync_marks_a_merged_pull_request_done(): void
    {
        $this->task->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open', 'status' => TaskStatus::PrOpen])->save();
        $this->git->shouldReceive('pullRequestDetails')->once()->with('acme/sub', 'widget', 5)->andReturn(new PullRequestDetails('merged', 'https://x/5'));

        $this->artisan('argos:tasks:sync-prs')->assertSuccessful();

        $task = $this->task->fresh();
        $this->assertSame('merged', $task->pr_state);
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertNotNull($task->done_at);
    }

    public function test_sync_cancels_a_task_whose_pull_request_was_closed(): void
    {
        $this->task->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open', 'status' => TaskStatus::PrOpen])->save();
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn(new PullRequestDetails('closed', 'https://x/5'));

        app(PullRequestService::class)->refresh($this->task);

        $task = $this->task->fresh();
        $this->assertSame('closed', $task->pr_state);
        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertNotNull($task->done_at);
    }

    public function test_sync_keeps_an_open_pull_request_open(): void
    {
        $this->task->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'draft', 'status' => TaskStatus::PrOpen])->save();
        $this->git->shouldReceive('pullRequestDetails')->once()->andReturn(new PullRequestDetails('open', 'https://x/5'));

        app(PullRequestService::class)->refresh($this->task);

        $task = $this->task->fresh();
        $this->assertSame('open', $task->pr_state);
        $this->assertSame(TaskStatus::PrOpen, $task->status);
        $this->assertNull($task->done_at);
    }

    public function test_the_commit_dialog_prefills_the_draft_and_can_go_on_to_push(): void
    {
        $page = Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->call('openShipDialog')
            ->assertActionMounted('commit')
            // Opens without waiting for Claude; the draft follows.
            ->assertActionDataSet(['subject' => '', 'then_push' => false])
            ->assertSet('shipDrafting', true)
            ->call('loadShipDraft')
            ->assertSet('shipDrafting', false)
            ->assertActionDataSet(['subject' => 'feat: add login', 'body' => 'Adds the login.'])
            ->setActionData(['then_push' => true])
            ->callMountedAction()
            ->assertNotified(__('workspaces.ship.committed'))
            ->assertActionMounted('push');
        $this->assertArrayNotHasKey('GitPush', $this->sent);

        // The pull request text is drafted from the whole branch (the fake answers with the commit shape).
        $this->git->shouldReceive('createPullRequest')->once()->withArgs(fn ($owner, $repo, $title) => $title === $this->task->title)->andReturn(['iid' => 9, 'web_url' => 'https://x/9', 'state' => 'opened']);
        $page->assertSet('shipDrafting', true)->call('loadShipDraft')->assertActionDataSet(['title' => $this->task->title, 'draft' => false])->callMountedAction()->assertNotified();

        $this->assertSame(9, $this->task->fresh()->pr_number);
    }

    public function test_text_typed_before_the_draft_arrives_is_kept(): void
    {
        Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->mountAction('commit')
            ->setActionData(['subject' => 'fix: my own words'])
            ->call('loadShipDraft')
            ->assertActionDataSet(['subject' => 'fix: my own words', 'body' => 'Adds the login.']);
    }

    public function test_the_push_dialog_only_confirms_when_the_pull_request_is_open(): void
    {
        $this->task->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open'])->save();
        $this->fakeDaemon([GitStatus::class => MockResponse::make(['branch' => 'ws/login', 'upstream' => 'origin/ws/login', 'ahead' => 2, 'behind' => 0, 'dirty' => false, 'files' => []])]);
        $this->git->shouldReceive('commentOnPullRequest')->once();

        $page = Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->call('openShipDialog')
            ->assertActionMounted('push');
        $this->assertStringStartsWith(trans_choice('workspaces.ship.push_summary', 2, ['count' => 2]), $page->get('shipSummary'));
        $page->callMountedAction()->assertNotified();

        $this->assertArrayNotHasKey('RunOneshot', $this->sent);
        $this->assertCount(1, $this->sent['GitPush']);
    }
}
