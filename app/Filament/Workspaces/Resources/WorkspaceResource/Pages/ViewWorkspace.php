<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Resources\WorkspaceResource\Pages;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Services\Workspace\WorkspaceMemberService;
use App\Services\Workspace\WorkspaceService;
use App\Services\Workspace\WorkspaceShipService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
    protected static string $resource = WorkspaceResource::class;

    /** Shown in the ship dialog (changed files, unpushed commits). */
    public string $shipSummary = '';

    /** The task shown in the chat (ship, gates and rebase act on it). */
    public ?string $taskId = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $workspace = $this->workspace();
        $requested = request()->query('task');
        $this->taskId = is_string($requested) && $workspace->tasks()->whereKey($requested)->exists()
            ? $requested
            : $workspace->tasks()->whereNull('archived_at')->latest('updated_at')->value('id');

        $task = $this->task();
        if ($task !== null && $task->pr_number !== null && in_array($task->pr_state, ['open', 'draft'], true) && ($task->pr_synced_at === null || $task->pr_synced_at->lt(now()->subMinutes(5)))) {
            app(WorkspaceShipService::class)->syncPullRequest($task);
        }

        // Opening a stopped workspace brings it back (files were kept).
        if ($workspace->status === WorkspaceStatus::Stopped && auth()->user()?->can('operate', $workspace)) {
            try {
                app(WorkspaceService::class)->start($workspace);
            } catch (WorkspaceException $e) {
                Notification::make()->title($e->getMessage())->warning()->send();
            }
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
        return 'filament.workspaces.view-workspace';
    }

    public function getTitle(): string|Htmlable
    {
        return $this->workspace()->name;
    }

    /** `/ship` in the composer opens the ship dialog. */
    #[On('workspace-ship')]
    public function openShipDialog(): void
    {
        $this->mountAction('ship');
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

    public function shipAction(): Action
    {
        return Action::make('ship')
            ->label(__('workspaces.ship.action'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                && ($task = $this->task()) !== null
                && (auth()->user()?->can('ship', $task) ?? false))
            ->modalHeading(__('workspaces.ship.heading'))
            ->modalWidth('2xl')
            ->fillForm(function (): array {
                /** @var User $user */
                $user = auth()->user();
                $task = $this->requireTask();
                try {
                    $draft = app(WorkspaceShipService::class)->draft($task, $user);
                } catch (\Throwable) {
                    $draft = ['subject' => '', 'body' => '', 'changed_files' => 0, 'ahead' => 0];
                }
                $gates = app(WorkspaceGateService::class)->summary($task);
                $failed = app(WorkspaceGateService::class)->latest($task)?->failedGates() !== [];
                $this->shipSummary = __('workspaces.ship.summary', ['files' => $draft['changed_files'], 'ahead' => $draft['ahead']])
                    .($draft['subject'] === '' ? ' '.__('workspaces.ship.no_draft') : '')
                    .($gates !== null ? ' '.__($failed ? 'workspaces.gates.ship_warning' : 'workspaces.gates.ship_summary', ['summary' => $gates]) : '');

                return ['subject' => $draft['subject'], 'body' => $draft['body'], 'draft' => false];
            })
            ->modalDescription(fn (): string => $this->shipSummary)
            ->schema([
                TextInput::make('subject')
                    ->label(__('workspaces.ship.subject'))
                    ->required()
                    ->maxLength(120),
                Textarea::make('body')
                    ->label(__('workspaces.ship.body'))
                    ->rows(5),
                Toggle::make('draft')
                    ->label(__('workspaces.ship.draft'))
                    ->visible(fn (): bool => $this->task()?->pr_number === null),
            ])
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    $url = app(WorkspaceShipService::class)->ship($this->requireTask(), $user, (string) $data['subject'], (string) ($data['body'] ?? ''), (bool) ($data['draft'] ?? false));
                    Notification::make()->title(__('workspaces.ship.shipped', ['url' => $url]))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
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
            ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                && ($task = $this->task()) !== null
                && (auth()->user()?->can('prompt', $task) ?? false))
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

    public function membersAction(): Action
    {
        return Action::make('members')
            ->label(__('workspaces.actions.add_member'))
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => auth()->user()?->can('manageMembers', $this->workspace()) ?? false)
            ->modalDescription(__('workspaces.actions.subscription_hint'))
            ->schema([
                Select::make('user_id')
                    ->label(__('workspaces.members.user'))
                    ->options(fn (): array => User::query()
                        ->whereNotIn('id', $this->workspace()->members()->pluck('user_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('role')
                    ->label(__('workspaces.members.role'))
                    ->options(collect(WorkspaceRole::cases())->mapWithKeys(fn (WorkspaceRole $r): array => [$r->value => $r->label()])->all())
                    ->default(WorkspaceRole::Member->value)
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                $member = User::query()->findOrFail($data['user_id']);
                try {
                    app(WorkspaceMemberService::class)->add($this->workspace(), $actor, $member, WorkspaceRole::from($data['role']));
                    Notification::make()->title(__('workspaces.actions.member_added', ['name' => $member->name]))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
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

        return [
            'workspace' => $workspace,
            'canOperate' => auth()->user()?->can('operate', $workspace) ?? false,
            'running' => $workspace->status === WorkspaceStatus::Running,
            'transitional' => in_array($workspace->status, [WorkspaceStatus::Provisioning, WorkspaceStatus::Starting, WorkspaceStatus::Stopping], true),
        ];
    }
}
