<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\DemoStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspacePreviewService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Preview of the task shown in the chat: start/rebuild/stop its live demo,
 * re-run the setup commands and show the build log; the preview opens in
 * its own tab.
 */
class PreviewPanel extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $taskId;

    /** When a deploy was queued and its demo row does not exist yet. */
    public ?string $queuedAt = null;

    public function mount(string $workspaceId, string $taskId): void
    {
        $this->workspaceId = $workspaceId;
        $this->taskId = $taskId;
        abort_unless($this->user()->can('view', $this->task()), 403);
    }

    public function start(): void
    {
        $this->attempt(function (): void {
            app(WorkspacePreviewService::class)->start($this->task(), $this->user());
            $this->queuedAt = now()->toIso8601String();
        });
    }

    public function rerun(): void
    {
        $this->attempt(function (): void {
            app(WorkspacePreviewService::class)->rerunCommands($this->task(), $this->user());
            Notification::make()->title(__('workspaces.preview.rerun_queued'))->success()->send();
        });
    }

    public function stop(): void
    {
        $this->attempt(fn () => app(WorkspacePreviewService::class)->stop($this->task(), $this->user()));
        $this->queuedAt = null;
    }

    public function render(): View
    {
        $task = $this->task();
        $workspace = $task->workspace;
        $demo = $task->currentDemo();
        if ($this->queuedAt !== null) {
            $queuedAt = Carbon::parse($this->queuedAt);
            // The job created its demo row, or never ran (queue down): stop waiting.
            if (($demo?->created_at !== null && $demo->created_at->gte($queuedAt->subSecond())) || $queuedAt->lt(now()->subMinutes(10))) {
                $this->queuedAt = null;
            }
        }

        return view('livewire.workspace.preview-panel', [
            'workspace' => $workspace,
            'task' => $task,
            'demo' => $demo,
            'reason' => app(WorkspacePreviewService::class)->unavailableReason($task),
            'canOperate' => $this->user()->can('prompt', $task),
            'building' => $this->queuedAt !== null || $demo?->status === DemoStatus::Building,
            'queued' => $this->queuedAt !== null,
        ]);
    }

    private function task(): Task
    {
        return Task::query()->with('workspace')->where('workspace_id', $this->workspaceId)->findOrFail($this->taskId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }
}
