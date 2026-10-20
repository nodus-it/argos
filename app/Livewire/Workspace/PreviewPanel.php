<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\DemoStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspacePreviewService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Preview tool of the workbench: start/rebuild/stop the live demo, re-run the
 * setup commands and show the build log; the preview opens in its own tab.
 */
class PreviewPanel extends Component
{
    #[Locked]
    public string $workspaceId;

    /** When a deploy was queued and its demo row does not exist yet. */
    public ?string $queuedAt = null;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);
    }

    public function start(): void
    {
        $this->attempt(function (): void {
            app(WorkspacePreviewService::class)->start($this->workspace(), $this->user());
            $this->queuedAt = now()->toIso8601String();
        });
    }

    public function rerun(): void
    {
        $this->attempt(function (): void {
            app(WorkspacePreviewService::class)->rerunCommands($this->workspace(), $this->user());
            Notification::make()->title(__('workspaces.preview.rerun_queued'))->success()->send();
        });
    }

    public function stop(): void
    {
        $this->attempt(fn () => app(WorkspacePreviewService::class)->stop($this->workspace(), $this->user()));
        $this->queuedAt = null;
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $demo = $workspace->currentDemo();
        if ($this->queuedAt !== null) {
            $queuedAt = Carbon::parse($this->queuedAt);
            // The job created its demo row, or never ran (queue down): stop waiting.
            if (($demo?->created_at !== null && $demo->created_at->gte($queuedAt->subSecond())) || $queuedAt->lt(now()->subMinutes(10))) {
                $this->queuedAt = null;
            }
        }

        return view('livewire.workspace.preview-panel', [
            'workspace' => $workspace,
            'demo' => $demo,
            'reason' => app(WorkspacePreviewService::class)->unavailableReason($workspace),
            'canOperate' => $this->user()->can('operate', $workspace),
            'building' => $this->queuedAt !== null || $demo?->status === DemoStatus::Building,
            'queued' => $this->queuedAt !== null,
        ]);
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->with('repoProfile')->findOrFail($this->workspaceId);
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
            Notification::make()->title(__('workspaces.thread.viewer_hint'))->warning()->send();
        }
    }
}
