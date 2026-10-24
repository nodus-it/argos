<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\PermissionMode;
use App\Enums\TaskEventType;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkerImageBuildStatus;
use App\Enums\WorkerImageEntityStatus;
use App\Enums\WorkerSource;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceSetupStatus;
use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource\Pages\EditWorkspaceSettings;
use App\Jobs\Workspace\CheckWorkspaceSetupJob;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceService;
use App\Services\Workspace\WorkspaceSetupService;
use App\Services\Workspace\WorkspaceShipService;
use App\Testing\FakeWorkspaceDaemon;
use App\Workers\Compose\WorkerImageBuilder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Workspace setup: inspecting a new repo, the setup task and its brief,
 * "Test setup" (ship, image, setup steps, preview) and where a tested setup
 * applies until its pull request is merged.
 */
class WorkspaceSetupTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private GitServiceContract&MockInterface $git;

    /** @var array<string, string> path => contents in the repo */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        foreach (['php-8.5' => 'PHP 8.5', 'php-8.4' => 'PHP 8.4', 'node-22' => 'Node 22'] as $name => $label) {
            WorkerStack::factory()->builtin()->create(['name' => $name, 'label' => $label, 'status' => WorkerImageEntityStatus::Active]);
        }
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running, 'default_branch' => 'main', 'live_demo_enabled' => false]);
        $this->admin = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->admin->id, 'role' => WorkspaceRole::Admin]);
        AgentCredential::factory()->ownedBy($this->admin)->create(['credentials' => ['token' => 'oat']]);

        $this->git = Mockery::mock(GitServiceContract::class);
        $this->git->shouldReceive('getFileContents')->andReturnUsing(fn (string $repo, string $path, string $ref): ?string => $this->files[$path] ?? null)->byDefault();
        $this->mock(GitServiceFactory::class)->shouldReceive('forWorkspace')->andReturn($this->git);
    }

    private function service(): WorkspaceSetupService
    {
        return app(WorkspaceSetupService::class);
    }

    private function setupTask(): Task
    {
        return app(TaskService::class)->create($this->workspace, $this->admin, AgentCredential::query()->firstOrFail(), 'Set up', options: ['kind' => TaskKind::Setup])->fresh();
    }

    public function test_a_laravel_repo_needs_nothing(): void
    {
        $this->files = ['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '<?php'];

        $this->assertSame(['project' => 'laravel', 'stack' => 'php-8.5', 'missing' => []], $this->service()->inspect($this->workspace));
    }

    public function test_a_laravel_repo_with_its_own_image_needs_a_preview(): void
    {
        $this->files = ['composer.json' => '{}', 'artisan' => '<?php', '.argos/worker.dockerfile' => "FROM debian:bookworm\n"];

        $this->assertSame(['project' => 'laravel', 'stack' => null, 'missing' => ['preview']], $this->service()->inspect($this->workspace));
        $this->assertSame('missing', $this->service()->sources($this->workspace)['preview']);
    }

    public function test_a_node_repo_needs_a_preview_and_setup_steps(): void
    {
        $this->files = ['package.json' => '{}'];

        $this->assertSame(['project' => 'node', 'stack' => 'node-22', 'missing' => ['preview', 'setup']], $this->service()->inspect($this->workspace));
    }

    public function test_php_pinned_to_8_4_gets_the_8_4_stack(): void
    {
        foreach (['8.4.*' => 'php-8.4', '~8.4.0' => 'php-8.4', '>=8.2 <8.5' => 'php-8.4', '^8.4.0' => 'php-8.5', '^8.2 || ^8.5' => 'php-8.5'] as $constraint => $stack) {
            $this->files = ['composer.json' => json_encode(['require' => ['php' => $constraint]]), 'artisan' => '<?php'];
            $this->assertSame($stack, $this->service()->inspect($this->workspace)['stack'], $constraint);
        }
    }

    public function test_an_unknown_repo_misses_everything(): void
    {
        $this->assertSame(['stack', 'preview', 'setup'], $this->service()->inspect($this->workspace)['missing']);
    }

    public function test_a_laravel_workspace_is_complete_but_untested_after_creating_it(): void
    {
        $this->files = ['composer.json' => '{}', 'artisan' => '<?php'];

        $this->assertNull($this->service()->afterCreate($this->workspace, $this->admin));

        $workspace = $this->workspace->fresh();
        $this->assertSame(WorkspaceSetupStatus::Untested, $workspace->setup_status);
        $this->assertSame('php-8.5', $workspace->workerStack?->name);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_laravel_workspace_on_the_defaults_points_the_admin_at_revise_setup(): void
    {
        $this->files = ['composer.json' => '{}', 'artisan' => '<?php'];

        $this->service()->afterCreate($this->workspace, $this->admin);

        $notification = $this->admin->notifications()->sole();
        $this->assertSame(__('workspaces.setup.defaults_title', ['workspace' => $this->workspace->name]), $notification->data['title']);
        $this->assertStringEndsWith('/'.$this->workspace->slug.'/settings', $notification->data['actions'][0]['url']);
    }

    public function test_a_laravel_workspace_with_its_own_setup_gets_no_hint(): void
    {
        $this->files = ['composer.json' => '{}', 'artisan' => '<?php', '.argos/setup.sh' => 'true', '.argos/demo.yml' => 'entry: {}'];

        $this->service()->afterCreate($this->workspace, $this->admin);

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_a_repo_with_gaps_starts_the_setup_task_with_its_brief(): void
    {
        $this->files = ['package.json' => '{}'];

        $task = $this->service()->afterCreate($this->workspace, $this->admin);

        $this->assertNotNull($task);
        $task->refresh();
        $this->assertSame(TaskKind::Setup, $task->kind);
        $this->assertSame($this->admin->id, $task->owner_user_id);
        $this->assertSame(PermissionMode::BypassPermissions, $task->permission_mode);
        $this->assertSame(WorkspaceSetupStatus::Running, $this->workspace->fresh()->setup_status);
        $this->assertSame('node-22', $this->workspace->fresh()->workerStack?->name);
        // Prepared right away by the fake daemon: Claude got the brief, once.
        $briefs = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage)->get();
        $this->assertCount(1, $briefs);
        $this->assertStringContainsString('the preview contract, the setup steps', (string) $briefs[0]->payload['text']);

        $this->service()->begin($task->fresh());
        $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage)->count());
        // Starting again reuses the open setup task.
        $this->assertSame($task->id, $this->service()->start($this->workspace, $this->admin)->id);
    }

    public function test_without_a_subscription_the_setup_waits(): void
    {
        AgentCredential::query()->delete();
        $this->files = ['package.json' => '{}'];

        $this->assertNull($this->service()->afterCreate($this->workspace, $this->admin));
        $this->assertSame(WorkspaceSetupStatus::NeedsAgent, $this->workspace->fresh()->setup_status);
    }

    public function test_members_cannot_start_the_setup(): void
    {
        $member = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);

        $this->expectException(AuthorizationException::class);
        $this->service()->start($this->workspace, $member);
    }

    public function test_creating_a_workspace_inspects_the_repo(): void
    {
        $creator = User::factory()->admin()->create();

        $workspace = app(WorkspaceService::class)->createWorkspace($creator, [
            'name' => 'Widget',
            'repo_url' => 'https://github.com/acme/widget',
            'platform' => 'github',
            'auth_method' => 'pat',
            'token' => 'test-token',
            'default_branch' => 'main',
        ]);

        // The queued inspection ran (sync in tests): an empty repo needs a
        // setup, and the creator has no Claude subscription to run it on.
        $this->assertSame(WorkspaceSetupStatus::NeedsAgent, $workspace->fresh()->setup_status);
    }

    private function turn(Task $task, string $path): void
    {
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::ToolCall, 'payload' => ['name' => 'Write', 'input' => ['file_path' => $path]]]);
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::TurnCompleted, 'payload' => []]);
    }

    public function test_a_turn_that_changes_the_setup_files_tests_them(): void
    {
        Bus::fake([CheckWorkspaceSetupJob::class]);
        $task = $this->setupTask();

        $this->turn($task, 'README.md');
        $this->service()->afterTurn($task);
        Bus::assertNotDispatched(CheckWorkspaceSetupJob::class);

        $this->turn($task, '/var/www/html/.argos/demo.yml');
        $this->service()->afterTurn($task);
        Bus::assertDispatchedTimes(CheckWorkspaceSetupJob::class, 1);
        $this->assertSame(WorkspaceSetupStatus::Testing, $this->workspace->fresh()->setup_status);
    }

    public function test_automatic_tests_stop_after_a_few_rounds(): void
    {
        Bus::fake([CheckWorkspaceSetupJob::class]);
        $task = $this->setupTask();
        foreach (range(1, WorkspaceSetupService::AUTO_TEST_ROUNDS) as $round) {
            TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::Notice, 'payload' => ['kind' => 'setup_testing']]);
        }
        $this->workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Failed])->save();

        $this->turn($task, '.argos/setup.sh');
        $this->service()->afterTurn($task);

        Bus::assertNotDispatched(CheckWorkspaceSetupJob::class);
        $this->assertSame(WorkspaceSetupStatus::Untested, $this->workspace->fresh()->setup_status);
    }

    public function test_test_setup_queues_the_check(): void
    {
        Bus::fake([CheckWorkspaceSetupJob::class]);
        $task = $this->setupTask();

        $this->service()->requestTest($task, $this->admin);

        Bus::assertDispatched(CheckWorkspaceSetupJob::class);
        $this->assertSame(WorkspaceSetupStatus::Testing, $this->workspace->fresh()->setup_status);
        $this->expectException(WorkspaceException::class);
        $this->service()->requestTest($task, $this->admin);
    }

    public function test_only_the_setup_task_can_be_tested(): void
    {
        $task = app(TaskService::class)->create($this->workspace, $this->admin, AgentCredential::query()->firstOrFail(), 'Login');

        $this->expectException(WorkspaceException::class);
        $this->service()->requestTest($task, $this->admin);
    }

    public function test_a_passing_check_applies_the_setup_from_its_branch(): void
    {
        $task = $this->setupTask();
        $this->mock(WorkspaceShipService::class)->shouldReceive('ship')->once()->andReturnUsing(function (Task $t): string {
            $t->forceFill(['pr_number' => 3, 'pr_url' => 'https://x/3', 'pr_state' => 'open', 'status' => TaskStatus::PrOpen])->save();

            return 'https://x/3';
        });

        $results = $this->service()->check($task, $this->admin);

        $this->assertSame(['ship', 'image', 'setup', 'preview'], array_column($results, 'step'));
        $this->assertNotContains(false, array_column($results, 'ok'));
        $this->assertSame([['ref' => $task->branch, 'env' => []]], FakeWorkspaceDaemon::$setupChecks);
        $workspace = $this->workspace->fresh();
        $this->assertSame(WorkspaceSetupStatus::Ready, $workspace->setup_status);
        $this->assertSame($task->branch, $workspace->setup_branch);
        $this->assertSame($task->branch, $workspace->setupRef());
        $this->assertTrue(TaskEvent::query()->where('task_id', $task->id)->where('payload->kind', 'setup_passed')->exists());
    }

    public function test_a_failing_setup_step_goes_back_to_claude(): void
    {
        $task = $this->setupTask();
        $task->forceFill(['pr_number' => 3, 'pr_url' => 'https://x/3'])->save();
        $this->mock(WorkspaceShipService::class)->shouldReceive('ship')->andThrow(WorkspaceException::translated('workspaces.ship.nothing'));
        FakeWorkspaceDaemon::$setupCheck = ['setup' => 'failed', 'log' => 'composer: missing ext-gd'];
        $before = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage)->count();

        $results = $this->service()->check($task->fresh(), $this->admin);

        $this->assertSame([true, true, false], array_column($results, 'ok'));
        $this->assertSame(WorkspaceSetupStatus::Failed, $this->workspace->fresh()->setup_status);
        $this->assertNull($this->workspace->fresh()->setup_branch);
        $messages = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage)->get();
        $this->assertCount($before + 1, $messages);
        $this->assertStringContainsString('missing ext-gd', (string) $messages->last()->payload['text']);
    }

    public function test_a_repo_image_is_built_and_used_after_the_check(): void
    {
        $task = $this->setupTask();
        $this->files = ['.argos/worker.dockerfile' => "FROM debian:bookworm\n"];
        $this->mock(WorkspaceShipService::class)->shouldReceive('ship')->andReturn('https://x/3');
        $build = new WorkerImageBuild(['status' => WorkerImageBuildStatus::Ready, 'build_log' => 'ok']);
        $this->mock(WorkerImageBuilder::class)->shouldReceive('build')->once()->andReturn($build);

        $this->service()->check($task, $this->admin);

        $this->assertSame(WorkerSource::Byoi, $this->workspace->fresh()->worker_source);
        $this->assertTrue(WorkerStack::query()->where('name', 'byoi-'.$this->workspace->id)->exists());
    }

    public function test_merging_the_setup_moves_it_to_the_default_branch(): void
    {
        $task = $this->setupTask();
        $this->workspace->forceFill(['setup_branch' => $task->branch, 'setup_status' => WorkspaceSetupStatus::Ready])->save();
        $task->forceFill(['pr_state' => 'merged'])->save();

        $this->service()->pullRequestFinished($task->fresh());

        $this->assertNull($this->workspace->fresh()->setup_branch);
        $this->assertSame('main', $this->workspace->fresh()->setupRef());
        $this->assertSame(WorkspaceSetupStatus::Ready, $this->workspace->fresh()->setup_status);
    }

    public function test_new_tasks_run_the_setup_steps_of_an_unmerged_setup(): void
    {
        $this->workspace->forceFill(['setup_branch' => 'argos/set-up'])->save();

        $task = app(TaskService::class)->create($this->workspace, $this->admin, AgentCredential::query()->firstOrFail(), 'Login');

        $this->assertSame('argos/set-up', FakeWorkspaceDaemon::$prepared[$task->slug]['setup_ref'] ?? null);
    }

    public function test_the_chat_offers_test_setup_on_the_setup_task(): void
    {
        Bus::fake([CheckWorkspaceSetupJob::class]);
        $task = $this->setupTask();

        Livewire::actingAs($this->admin)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $task->id)
            ->assertSee(__('workspaces.setup.banner'))
            ->call('testSetup');

        Bus::assertDispatched(CheckWorkspaceSetupJob::class);
    }

    public function test_the_settings_show_the_setup_and_revise_it(): void
    {
        Filament::setCurrentPanel('app');
        $this->workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Failed, 'setup_check' => [['step' => 'ship', 'ok' => true, 'log' => ''], ['step' => 'image', 'ok' => false, 'log' => 'x']]])->save();

        Livewire::actingAs($this->admin)
            ->test(EditWorkspaceSettings::class, ['record' => $this->workspace->getKey()])
            ->assertSee(WorkspaceSetupStatus::Failed->label())
            ->assertSee(__('workspaces.setup.steps.image'))
            ->assertSee(__('workspaces.setup.source_preview').': '.__('workspaces.setup.source.missing'))
            ->assertSee(__('workspaces.setup.revise'))
            ->callAction(TestAction::make('reviseSetup')->schemaComponent('setupActions', schema: 'environmentForm'));

        $this->assertTrue(Task::query()->where('kind', TaskKind::Setup->value)->exists());
    }
}
