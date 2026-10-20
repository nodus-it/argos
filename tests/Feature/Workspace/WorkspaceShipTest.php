<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\GitProvider;
use App\Enums\ThreadEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\GitCommit;
use App\Integrations\WorkspaceDaemon\Requests\GitDiff;
use App\Integrations\WorkspaceDaemon\Requests\GitPush;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Integrations\WorkspaceDaemon\Requests\RunOneshot;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
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
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class WorkspaceShipTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $editor;

    private GitServiceContract&MockInterface $git;

    /** @var array<string, list<array<string, mixed>>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([ThreadStream::class]);
        $profile = RepoProfile::factory()->create(['url' => 'https://gitlab.com/acme/sub/widget', 'platform' => GitProvider::GitLab, 'token' => 'glpat-x']);
        $this->workspace = Workspace::factory()->create(['repo_profile_id' => $profile->id, 'status' => WorkspaceStatus::Running, 'branch' => 'ws/login', 'base_branch' => 'main']);
        $this->editor = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Editor]);
        AgentCredential::factory()->ownedBy($this->editor)->create(['credentials' => ['token' => 'oat-ada']]);

        $this->git = Mockery::mock(GitServiceContract::class);
        $this->mock(GitServiceFactory::class)->shouldReceive('fromRepoProfile')->andReturn($this->git);
        $this->fakeDaemon();
    }

    /** @param array<class-string, MockResponse> $overrides */
    private function fakeDaemon(array $overrides = []): void
    {
        $defaults = [
            GitStatus::class => MockResponse::make(['branch' => 'ws/login', 'upstream' => null, 'ahead' => 0, 'behind' => 0, 'dirty' => true, 'files' => [['path' => 'a.php', 'status' => 'M']]]),
            GitDiff::class => MockResponse::make(['diff' => "diff --git a/a.php b/a.php\n+new\n", 'truncated' => false]),
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
        $draft = $this->service()->draft($this->workspace, $this->editor);

        $this->assertSame(['subject' => 'feat: add login', 'body' => 'Adds the login.', 'changed_files' => 1, 'ahead' => 0], $draft);
        $prompt = $this->sent['RunOneshot'][0];
        $this->assertSame('oat-ada', $prompt['oauth_token']);
        $this->assertStringContainsString('+new', $prompt['user_prompt']);
        $this->assertSame(['subject', 'body'], $prompt['json_schema']['required']);
    }

    public function test_draft_is_empty_without_an_agent_or_when_claude_fails(): void
    {
        AgentCredential::query()->delete();
        $this->assertSame('', $this->service()->draft($this->workspace, $this->editor)['subject']);

        AgentCredential::factory()->ownedBy($this->editor)->create();
        $this->fakeDaemon([RunOneshot::class => MockResponse::make(['error' => ['code' => 'oneshot_failed', 'message' => 'x']], 502)]);
        $this->assertSame('', $this->service()->draft($this->workspace, $this->editor)['subject']);
    }

    public function test_ship_commits_pushes_and_opens_the_merge_request(): void
    {
        $thread = Thread::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body, $head, $base, $options) => $owner === 'acme/sub' && $repo === 'widget' && $title === 'feat: add login'
                && str_contains($body, 'Adds the login.') && str_contains($body, '/w/workspaces/'.$this->workspace->id) && $head === 'ws/login' && $base === 'main' && $options === ['draft' => true])
            ->andReturn(['iid' => 5, 'web_url' => 'https://gitlab.com/acme/sub/widget/-/merge_requests/5', 'state' => 'opened']);

        $url = $this->service()->ship($this->workspace, $this->editor, 'feat: add login', 'Adds the login.', true);

        $this->assertSame('https://gitlab.com/acme/sub/widget/-/merge_requests/5', $url);
        $this->workspace->refresh();
        $this->assertSame(5, $this->workspace->pr_number);
        $this->assertSame('open', $this->workspace->pr_state);
        $this->assertNotNull($this->workspace->shipped_at);
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $this->sent['GitCommit'][0]['author']);
        $this->assertSame(['branch' => 'ws/login'], $this->sent['GitPush'][0]);
        $notice = ThreadEvent::query()->where('thread_id', $thread->id)->where('type', ThreadEventType::Notice->value)->firstOrFail();
        $this->assertSame('shipped', $notice->payload['kind']);
    }

    public function test_a_provider_failure_after_the_push_is_a_workspace_exception(): void
    {
        $this->git->shouldReceive('createPullRequest')->once()->andThrow(new \RuntimeException('422 head branch missing'));

        try {
            $this->service()->ship($this->workspace, $this->editor, 'feat: add login', '');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.ship.pr_failed', ['message' => '422 head branch missing']), $e->getMessage());
        }

        $this->assertCount(1, $this->sent['GitPush']);
        $this->workspace->refresh();
        $this->assertNull($this->workspace->pr_number);
        $this->assertNull($this->workspace->shipped_at);
    }

    public function test_draft_is_empty_when_the_daemon_is_unreachable(): void
    {
        $this->fakeDaemon([GitStatus::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'down']], 500)]);

        $this->assertSame(['subject' => '', 'body' => '', 'changed_files' => 0, 'ahead' => 0], $this->service()->draft($this->workspace, $this->editor));
    }

    public function test_shipping_an_issue_workspace_closes_and_comments_on_the_issue(): void
    {
        $this->workspace->forceFill(['external_issue' => [
            'binding_id' => 'b1', 'provider' => 'gitlab', 'owner' => 'acme', 'project' => 'sub/widget',
            'number' => '12', 'title' => 'Login', 'url' => 'https://gitlab.com/acme/sub/widget/-/issues/12', 'body' => '',
        ]])->save();
        $issues = $this->mock(WorkspaceIssueService::class);
        $issues->shouldReceive('pullRequestReference')->andReturn('Closes #12');
        $issues->shouldReceive('announcePullRequest')->once()->with(Mockery::type(Workspace::class), 'https://x/9');
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body) => str_contains($body, "Adds the login.\n\nCloses #12"))
            ->andReturn(['iid' => 9, 'web_url' => 'https://x/9', 'state' => 'opened']);

        $this->service()->ship($this->workspace, $this->editor, 'feat: add login', 'Adds the login.');
    }

    public function test_shipping_again_comments_on_the_existing_pull_request(): void
    {
        $this->workspace->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open'])->save();
        $this->git->shouldReceive('createPullRequest')->never();
        $this->git->shouldReceive('commentOnPullRequest')->once()->withArgs(fn ($owner, $repo, $id, $body) => $id === 5 && str_contains($body, 'feat: more'));

        $this->assertSame('https://x/5', $this->service()->ship($this->workspace, $this->editor, 'feat: more', ''));
    }

    public function test_nothing_to_ship(): void
    {
        $this->fakeDaemon([
            GitCommit::class => MockResponse::make(['error' => ['code' => 'no_changes', 'message' => 'Nothing to commit']], 409),
            GitDiff::class => MockResponse::make(['diff' => '', 'truncated' => false]),
        ]);

        $this->expectException(WorkspaceException::class);
        $this->expectExceptionMessage(__('workspaces.ship.nothing'));
        $this->service()->ship($this->workspace, $this->editor, 'feat: x', '');
    }

    public function test_a_rejected_push_explains_how_to_continue(): void
    {
        $this->fakeDaemon([GitPush::class => MockResponse::make(['error' => ['code' => 'rejected_non_fast_forward', 'message' => 'rejected']], 409)]);

        $this->expectExceptionMessage(__('workspaces.ship.rejected'));
        $this->service()->ship($this->workspace, $this->editor, 'feat: x', '');
    }

    public function test_viewers_cannot_ship_and_a_subject_is_required(): void
    {
        try {
            $this->service()->ship($this->workspace, $this->editor, '  ', '');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertArrayNotHasKey('GitCommit', $this->sent);
        }

        $viewer = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $this->expectException(AuthorizationException::class);
        $this->service()->ship($this->workspace, $viewer, 'feat: x', '');
    }

    public function test_sync_reads_the_pull_request_state(): void
    {
        $this->workspace->forceFill(['pr_number' => 5, 'pr_url' => 'https://x/5', 'pr_state' => 'open'])->save();
        $this->git->shouldReceive('getPullRequest')->once()->with('acme/sub', 'widget', 5)->andReturn(['iid' => 5, 'web_url' => 'https://x/5', 'state' => 'merged']);

        $this->artisan('argos:workspaces:sync-prs')->assertSuccessful();

        $this->assertSame('merged', $this->workspace->fresh()->pr_state);
    }

    public function test_the_ship_action_prefills_the_draft_and_ships(): void
    {
        $this->git->shouldReceive('createPullRequest')->once()->andReturn(['iid' => 9, 'web_url' => 'https://x/9', 'state' => 'opened']);

        Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->mountAction('ship')
            ->assertActionDataSet(['subject' => 'feat: add login', 'body' => 'Adds the login.'])
            ->callMountedAction()
            ->assertNotified();

        $this->assertSame(9, $this->workspace->fresh()->pr_number);
    }
}
