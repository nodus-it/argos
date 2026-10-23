<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\App\Resources\WorkspaceResource\Pages\Concerns\ManagesTask;
use App\Filament\App\Resources\WorkspaceResource\Pages\Concerns\ShipsTask;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Services\Workspace\WorkspaceService;
use App\Support\RequestedTask;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

/**
 * The workspace ("Fokus" layout): a header with the task switcher and the
 * ship button, the chat in the middle and a context sidebar (environment,
 * branch, gates, changed files) that turns into the review panel. The parts
 * are Livewire components: task-switcher, task-chat, context-panel.
 */
class ViewWorkspace extends ViewRecord
{
    use ManagesTask;
    use ShipsTask;

    protected static string $resource = WorkspaceResource::class;

    /** The task shown in the chat (ship, gates and rebase act on it). */
    public ?string $taskId = null;

    /** `/{workspace}/{task?}` — the task is read by RequestedTask. */
    public function mount(int|string $record, ?string $task = null): void
    {
        parent::mount($record);

        $workspace = $this->workspace();
        $this->taskId = RequestedTask::idOrLatest($workspace->id);

        // The pull request refreshes in the background (PullRequestService::details).
        /** @var User $user */
        $user = auth()->user();
        try {
            app(WorkspaceService::class)->resumeOnOpen($workspace, $user);
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    #[On('task-changed')]
    public function taskChanged(?string $id): void
    {
        $this->taskId = $id;
    }

    public function task(): ?Task
    {
        return $this->taskId !== null ? $this->workspace()->tasks()->find($this->taskId) : null;
    }

    public function workspace(): Workspace
    {
        /** @var Workspace $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getView(): string
    {
        return 'filament.app.view-workspace';
    }

    public function getTitle(): string|Htmlable
    {
        return $this->workspace()->name;
    }

    /** Re-read the record while the container changes state (polled). */
    public function refreshStatus(): void
    {
        $this->record = $this->workspace()->fresh() ?? $this->record;
    }

    /** Rendered in the page's own header (see view-workspace.blade.php). */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label(__('workspaces.actions.start'))
            ->icon('heroicon-o-play')
            ->visible(fn (): bool => in_array($this->workspace()->status, [WorkspaceStatus::Stopped, WorkspaceStatus::Failed], true)
                && (auth()->user()?->can('operate', $this->workspace()) ?? false))
            ->action(function (): void {
                try {
                    app(WorkspaceService::class)->start($this->workspace());
                    Notification::make()->title(__('workspaces.actions.started'))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
                $this->refreshStatus();
            });
    }

    public function gatesAction(): Action
    {
        return Action::make('gates')
            ->label(__('workspaces.gates.run'))
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                && ($task = $this->task()) !== null
                && (auth()->user()?->can('prompt', $task) ?? false))
            ->action(function (): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    app(WorkspaceGateService::class)->run($this->requireTask(), $user);
                    Notification::make()->title(__('workspaces.gates.started'))->success()->send();
                    $this->dispatch('workspace-focus', target: 'gates');
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->warning()->send();
                }
            });
    }

    public function rebaseAction(): Action
    {
        return Action::make('rebase')
            ->label(__('workspaces.ship.rebase'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            // Only when there is something to rebase onto: the pull request reports
            // conflicts or a base branch that moved on.
            ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                && ($task = $this->task()) !== null
                && (auth()->user()?->can('prompt', $task) ?? false)
                && $this->needsRebase($task))
            ->action(function (): void {
                /** @var User $user */
                $user = auth()->user();
                $task = $this->task();
                if ($task === null) {
                    Notification::make()->title(__('workspaces.ship.no_task'))->warning()->send();

                    return;
                }
                try {
                    app(TaskService::class)->send($task, $user, __('workspaces.ship.rebase_prompt', ['branch' => $task->base_branch]));
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    private function needsRebase(Task $task): bool
    {
        if ($task->pr_conflicts) {
            return true;
        }
        $details = app(PullRequestService::class)->details($task);

        return $details?->needsRebase() ?? false;
    }

    /** Workspace settings (repository, environment, resources, integrations, members). */
    public function settingsAction(): Action
    {
        return Action::make('settings')
            ->label(__('workspaces.actions.settings'))
            ->icon('heroicon-o-cog-6-tooth')
            ->visible(fn (): bool => auth()->user()?->can('update', $this->workspace()) ?? false)
            ->url(fn (): string => WorkspaceResource::getUrl('settings', ['record' => $this->workspace()]));
    }

    /** Keyboard shortcuts overlay (also on "?"). */
    public function shortcutsAction(): Action
    {
        return Action::make('shortcuts')
            ->label(__('workspaces.shortcuts.title'))
            ->icon('heroicon-o-command-line')
            ->alpineClickHandler("window.dispatchEvent(new CustomEvent('argos-shortcuts'))");
    }

    public function stopAction(): Action
    {
        return Action::make('stop')
            ->label(__('workspaces.actions.stop'))
            ->icon('heroicon-o-stop')
            ->visible(fn (): bool => in_array($this->workspace()->status, [WorkspaceStatus::Running, WorkspaceStatus::Failed], true)
                && (auth()->user()?->can('operate', $this->workspace()) ?? false))
            ->requiresConfirmation()
            ->action(function (): void {
                app(WorkspaceService::class)->stop($this->workspace());
                Notification::make()->title(__('workspaces.actions.stopped'))->success()->send();
                $this->refreshStatus();
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('workspaces.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->can('delete', $this->workspace()) ?? false)
            ->requiresConfirmation()
            ->modalDescription(__('workspaces.actions.delete_confirm'))
            ->action(function (): void {
                app(WorkspaceService::class)->deleteWorkspace($this->workspace());
                $this->redirect(WorkspaceResource::getUrl('index'));
            });
    }

    private function requireTask(): Task
    {
        return $this->task() ?? throw WorkspaceException::translated('workspaces.ship.no_task');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $workspace = $this->workspace();

        $task = $this->task()?->load(['owner', 'collaborators']);

        return [
            'workspace' => $workspace,
            'task' => $task,
            'canInvite' => $task !== null && (auth()->user()?->can('invite', $task) ?? false),
            'canOperate' => auth()->user()?->can('operate', $workspace) ?? false,
            'running' => $workspace->status === WorkspaceStatus::Running,
            'transitional' => in_array($workspace->status, [WorkspaceStatus::Provisioning, WorkspaceStatus::Starting, WorkspaceStatus::Stopping], true),
        ];
    }
}
