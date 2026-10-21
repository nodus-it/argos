<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AgentName;
use App\Enums\DemoStatus;
use App\Enums\PermissionMode;
use App\Enums\TaskEventType;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkerImageBuildStatus;
use App\Enums\WorkerSource;
use App\Enums\WorkspaceSetupStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Jobs\BuildWorkerImageJob;
use App\Jobs\Workspace\CheckWorkspaceSetupJob;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use App\Models\Workspace;
use App\Services\Demo\DemoConfigLocator;
use App\Services\Demo\DemoDeployer;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Worker\WorkerSidecarManager;
use App\Workers\Compose\WorkerImageBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The workspace's environment setup. A new workspace is inspected (stack,
 * preview contract, setup steps); when something is missing a setup task
 * starts — Claude writes `.argos/` on the creating admin's subscription.
 * "Test setup" ships the files as a pull request and checks them: the image
 * builds with the required tools, the setup steps pass in a fresh worktree
 * and the preview comes up healthy. A tested setup applies to new tasks right
 * away (from its branch); after the merge it comes from the default branch.
 */
class WorkspaceSetupService
{
    public const STEPS = ['ship', 'image', 'setup', 'preview'];

    private const PROMPT_PATH = 'worker/prompts/workspace-setup.md';

    public function __construct(
        private readonly GitServiceFactory $git,
        private readonly TaskService $tasks,
        private readonly AgentPool $agents,
        private readonly WorkspaceService $workspaces,
    ) {}

    /**
     * What the repo brings at its setup ref.
     *
     * @return array{project: 'laravel'|'php'|'node'|'unknown', stack: string|null, missing: list<'stack'|'preview'|'setup'>}
     */
    public function inspect(Workspace $workspace): array
    {
        $service = $this->git->forWorkspace($workspace);
        $repo = $workspace->getOwnerRepo();
        $ref = $workspace->setupRef();
        $file = fn (string $path): ?string => $service->getFileContents($repo, $path, $ref);

        $composer = $file('composer.json');
        $laravel = $composer !== null && $file('artisan') !== null;
        $node = $composer === null && $file('package.json') !== null;
        $ownImage = $file('.argos/worker.dockerfile') !== null;

        $stack = match (true) {
            $ownImage => null,
            $composer !== null => $this->phpStack($composer),
            $node => 'node-22',
            default => null,
        };
        $missing = [];
        if (! $ownImage && $stack === null) {
            $missing[] = 'stack';
        }
        if (! $laravel && $file(DemoConfigLocator::SETTINGS_PATH) === null) {
            $missing[] = 'preview';
        }
        if (! $laravel && $file('.argos/setup.sh') === null) {
            $missing[] = 'setup';
        }

        return [
            'project' => $laravel ? 'laravel' : ($composer !== null ? 'php' : ($node ? 'node' : 'unknown')),
            'stack' => $stack,
            'missing' => $missing,
        ];
    }

    /**
     * Where the preview contract and the setup steps come from, for the
     * settings (cached briefly): `repo`, `default` (Laravel defaults),
     * `missing`, or `unknown` when the repo cannot be read.
     *
     * @return array{preview: string, setup: string}
     */
    public function sources(Workspace $workspace): array
    {
        return Cache::remember('ws-setup-sources:'.$workspace->id.':'.$workspace->setupRef(), 300, function () use ($workspace): array {
            try {
                $service = $this->git->forWorkspace($workspace);
                $file = fn (string $path): bool => $service->getFileContents($workspace->getOwnerRepo(), $path, $workspace->setupRef()) !== null;
                $laravel = $file('artisan');

                return [
                    'preview' => $file(DemoConfigLocator::SETTINGS_PATH) ? 'repo' : ($laravel ? 'default' : 'missing'),
                    'setup' => $file('.argos/setup.sh') ? 'repo' : ($laravel ? 'default' : 'missing'),
                ];
            } catch (Throwable) {
                return ['preview' => 'unknown', 'setup' => 'unknown'];
            }
        });
    }

    /**
     * Right after creating a workspace: pick the stack and start the setup
     * task when something is missing. Never throws — a repo that cannot be
     * read only marks the setup as needed.
     */
    public function afterCreate(Workspace $workspace, User $admin): ?Task
    {
        try {
            $found = $this->inspect($workspace);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Workspace inspection failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
            $workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Needed])->save();

            return null;
        }

        if ($found['stack'] !== null && $workspace->worker_stack_id === null && $workspace->worker_source !== WorkerSource::Byoi) {
            $workspace->worker_stack_id = WorkerStack::query()->where('name', $found['stack'])->value('id');
        }
        if ($found['missing'] === []) {
            $workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Ready])->save();

            return null;
        }
        $workspace->save();

        try {
            return $this->start($workspace, $admin);
        } catch (WorkspaceException) {
            return null;
        } catch (Throwable $e) {
            // The container start reports its own failure on the workspace.
            Log::channel('argos')->warning('Starting the setup task failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);

            return $this->openTask($workspace);
        }
    }

    /**
     * Start the setup task ("Revise setup"), owned by `$by` and running on
     * their subscription. An open setup task is reused.
     */
    public function start(Workspace $workspace, User $by): Task
    {
        Gate::forUser($by)->authorize('setup', $workspace);
        $open = $this->openTask($workspace);
        if ($open !== null) {
            return $open;
        }

        $agent = $this->agents->usableAgentsFor($by)->first();
        if ($agent === null) {
            $workspace->forceFill(['setup_status' => WorkspaceSetupStatus::NeedsAgent])->save();

            throw WorkspaceException::translated('workspaces.setup.needs_agent');
        }

        $task = $this->tasks->create($workspace, $by, $agent, __('workspaces.setup.task_title'), mode: PermissionMode::AcceptEdits, options: ['kind' => TaskKind::Setup]);
        $workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Running])->save();
        if ($workspace->status === WorkspaceStatus::Stopped) {
            $this->workspaces->start($workspace);
        }

        return $task;
    }

    /** The unfinished setup task of a workspace, if any. */
    public function openTask(Workspace $workspace): ?Task
    {
        return Task::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', TaskKind::Setup->value)
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereNull('archived_at')
            ->latest()
            ->first();
    }

    /** The setup task is prepared: hand Claude its brief (once). */
    public function begin(Task $task): void
    {
        if ($task->kind !== TaskKind::Setup || $task->events()->where('type', TaskEventType::UserMessage)->exists()) {
            return;
        }

        try {
            $found = $this->inspect($task->workspace);
        } catch (Throwable) {
            $found = ['project' => 'unknown', 'stack' => null, 'missing' => ['stack', 'preview', 'setup']];
        }
        $prompt = strtr((string) file_get_contents(base_path(self::PROMPT_PATH)), [
            '{{missing}}' => $found['missing'] === [] ? 'nothing — the person asked to revise the setup' : implode(', ', array_map(fn (string $m): string => match ($m) {
                'stack' => 'a fitting stack',
                'preview' => 'the preview contract',
                default => 'the setup steps',
            }, $found['missing'])),
            '{{project}}' => $found['project'],
            '{{stack}}' => $task->workspace->workerStack->label ?? ($task->workspace->worker_source === WorkerSource::Byoi ? '.argos/worker.dockerfile' : (string) config('argos.compose.default_stack')),
        ]);

        try {
            $this->tasks->send($task, $task->owner, $prompt);
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Setup brief could not be sent', ['task' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    /** "Test setup": queue the check of the setup task's branch. */
    public function requestTest(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('ship', $task);
        if ($task->kind !== TaskKind::Setup) {
            throw WorkspaceException::translated('workspaces.setup.not_setup_task');
        }
        if ($task->workspace->setup_status === WorkspaceSetupStatus::Testing) {
            throw WorkspaceException::translated('workspaces.setup.testing');
        }

        $task->workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Testing])->save();
        $this->tasks->systemNotice($task, 'setup_testing');
        CheckWorkspaceSetupJob::dispatch($task->id, $by->id);
    }

    /**
     * Run the check (queued): ship, image, setup steps, preview. Stops at the
     * first failing step and hands its log to Claude.
     *
     * @return list<array{step: string, ok: bool, log: string}>
     */
    public function check(Task $task, User $by): array
    {
        $workspace = $task->workspace;
        $results = [];
        $ownImage = false;

        foreach (self::STEPS as $step) {
            try {
                [$ok, $log] = match ($step) {
                    'ship' => $this->checkShip($task, $by),
                    'image' => $this->checkImage($task, $ownImage),
                    'setup' => $this->checkSetupSteps($task),
                    default => $this->checkPreview($task),
                };
            } catch (Throwable $e) {
                [$ok, $log] = [false, $e->getMessage()];
            }
            $results[] = ['step' => $step, 'ok' => $ok, 'log' => mb_substr($log, -8000)];
            if (! $ok) {
                break;
            }
        }

        $passed = count($results) === count(self::STEPS) && ! in_array(false, array_column($results, 'ok'), true);
        $task->refresh();
        $workspace->forceFill([
            'setup_status' => $passed ? WorkspaceSetupStatus::Ready : WorkspaceSetupStatus::Failed,
            'setup_check' => $results,
            // Applies to new tasks right away; after the merge the default branch has it.
            'setup_branch' => $passed && $task->pr_state !== 'merged' ? $task->branch : $workspace->setup_branch,
            'worker_source' => $passed && $ownImage ? WorkerSource::Byoi : $workspace->worker_source,
        ])->save();

        $this->tasks->systemNotice($task, $passed ? 'setup_passed' : 'setup_failed', [
            'steps' => array_map(fn (array $r): array => ['step' => $r['step'], 'ok' => $r['ok']], $results),
            'url' => $task->pr_url,
        ]);
        if (! $passed) {
            $failed = $results[array_key_last($results)];
            try {
                $this->tasks->send($task, $by, __('workspaces.setup.fix_prompt', ['step' => __('workspaces.setup.steps.'.$failed['step'])])."\n\n```\n".$failed['log']."\n```");
            } catch (Throwable $e) {
                Log::channel('argos')->info('Setup failure could not be handed to Claude', ['task' => $task->id, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * The latest image build of the workspace's stack (built-in, or the one
     * made from `.argos/worker.dockerfile`), without asking the provider.
     */
    public function currentImage(Workspace $workspace): ?WorkerImageBuild
    {
        $stack = $workspace->worker_source === WorkerSource::Byoi
            ? WorkerStack::query()->where('name', 'byoi-'.$workspace->id)->first()
            : ($workspace->workerStack ?? WorkerStack::query()->where('name', (string) config('argos.compose.default_stack'))->first());

        return $stack?->imageBuilds()->where('agent_name', AgentName::ClaudeCode->value)->latest('updated_at')->first();
    }

    /** "Rebuild": build the stack image again; the container picks it up on its next start. */
    public function rebuildImage(Workspace $workspace, User $by): void
    {
        Gate::forUser($by)->authorize('setup', $workspace);

        try {
            $resolved = app(WorkerImageResolver::class)->resolveForWorkspace($workspace, $workspace->setupRef());
        } catch (Throwable $e) {
            throw new WorkspaceException($e->getMessage());
        }
        BuildWorkerImageJob::dispatch($resolved->stack->id, AgentName::ClaudeCode);
    }

    /** The setup task's pull request was merged or closed. */
    public function pullRequestFinished(Task $task): void
    {
        if ($task->kind !== TaskKind::Setup) {
            return;
        }
        $workspace = $task->workspace;
        if ($workspace->setup_branch !== $task->branch) {
            return;
        }

        $workspace->forceFill([
            'setup_branch' => null,
            'setup_status' => $task->pr_state === 'merged' ? $workspace->setup_status : WorkspaceSetupStatus::Needed,
        ])->save();
    }

    /** @return array{0: bool, 1: string} */
    private function checkShip(Task $task, User $by): array
    {
        try {
            $url = app(WorkspaceShipService::class)->ship($task, $by, 'chore: set up the Argos workspace', (string) __('workspaces.setup.pr_body'));
        } catch (WorkspaceException $e) {
            // Nothing new since the last test: the branch is pushed already.
            if ($task->fresh()?->pr_number === null) {
                throw $e;
            }
            $url = (string) $task->pr_url;
        }

        return [true, $url];
    }

    /** @return array{0: bool, 1: string} */
    private function checkImage(Task $task, bool &$ownImage): array
    {
        $resolved = app(WorkerImageResolver::class)->resolveRepoImage($task->workspace, (string) $task->branch);
        if ($resolved === null) {
            return [true, __('workspaces.setup.builtin_stack')];
        }

        $ownImage = true;
        $build = app(WorkerImageBuilder::class)->build($resolved);

        return [$build->status === WorkerImageBuildStatus::Ready, (string) $build->build_log];
    }

    /** @return array{0: bool, 1: string} */
    private function checkSetupSteps(Task $task): array
    {
        $workspace = $task->workspace;
        $env = app(TaskEnvironment::class)->values($task);
        if (isset($env['DB_DATABASE'])) {
            $env['DB_DATABASE'] = substr('check_'.str_replace('-', '_', $workspace->slug), 0, 64);
            app(WorkerSidecarManager::class)->dropTaskDatabase($workspace, $env['DB_DATABASE']);
            app(WorkerSidecarManager::class)->createTaskDatabase($workspace, $env['DB_DATABASE']);
        }

        try {
            $result = app(WorkspaceDaemonClient::class)->for($workspace, 1900)->setupCheck((string) $task->branch, $env);
        } catch (DaemonTransportException $e) {
            return [false, $e->getMessage()];
        }

        return [$result['setup'] !== 'failed', $result['log']];
    }

    /** @return array{0: bool, 1: string} */
    private function checkPreview(Task $task): array
    {
        $reason = app(WorkspacePreviewService::class)->unavailableReason($task);
        if ($reason !== null) {
            return [true, __('workspaces.setup.preview_skipped', ['reason' => $reason])];
        }

        $demo = app(DemoDeployer::class)->deploy($task);

        return [$demo->status === DemoStatus::Live, (string) $demo->build_log];
    }

    /**
     * PHP 8.5 unless composer.json pins PHP to 8.4 (`8.4.*`, `~8.4.0`,
     * `8.4.x`) without naming 8.5.
     */
    private function phpStack(string $composerJson): string
    {
        $constraint = json_decode($composerJson, true)['require']['php'] ?? null;
        // 8.5 named as allowed (not as an upper bound) keeps the default.
        if (! is_string($constraint) || preg_match('/(^|[\s|,^~>=])8\.5/', $constraint) === 1) {
            return 'php-8.5';
        }

        return preg_match('/(^|[\s|,])(~\s*8\.4\.\d|8\.4\.(\*|\d)|<\s*8\.5)/', $constraint) === 1 ? 'php-8.4' : 'php-8.5';
    }
}
