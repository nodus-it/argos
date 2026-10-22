<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\TaskMembershipService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\TaskTicketService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceService;
use App\Services\Workspace\WorkspaceShipService;
use App\Support\RequestedTask;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
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

    /** Shown in the commit and push dialogs (changes, commits, gates). */
    public string $shipSummary = '';

    /** True while Claude drafts the text of the open commit or push dialog. */
    public bool $shipDrafting = false;

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

    /**
     * Action bar and `/ship`: open the commit or the push dialog — without a
     * step, whichever comes next (commit while something is uncommitted).
     */
    #[On('workspace-ship')]
    public function openShipDialog(?string $step = null): void
    {
        if ($step === null) {
            $task = $this->task();
            try {
                $step = $task !== null && app(WorkspaceShipService::class)->pending($task)['changed_files'] > 0 ? 'commit' : 'push';
            } catch (\Throwable) {
                $step = 'commit';
            }
        }
        $this->mountAction($step === 'push' ? 'push' : 'commit');
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

    /** Commit what is not committed yet — message drafted by Claude, optionally pushing right after. */
    public function commitAction(): Action
    {
        return Action::make('commit')
            ->label(__('workspaces.ship.commit'))
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->visible(fn (): bool => $this->canShip())
            ->modalHeading(__('workspaces.ship.commit_heading'))
            ->modalWidth('2xl')
            // Opens at once; Claude's draft follows (loadShipDraft).
            ->fillForm(function (): array {
                $task = $this->requireTask();
                try {
                    $files = app(WorkspaceShipService::class)->pending($task)['changed_files'];
                } catch (\Throwable) {
                    $files = 0;
                }
                $this->shipSummary = trans_choice('workspaces.ship.commit_summary', $files, ['count' => $files]).$this->gateNote($task);
                $this->shipDrafting = true;

                return ['subject' => '', 'body' => '', 'then_push' => false];
            })
            ->modalContent(fn (): HtmlString => $this->draftingHint())
            ->modalDescription(fn (): string => $this->shipSummary)
            ->schema([
                TextInput::make('subject')
                    ->label(__('workspaces.ship.subject'))
                    ->required()
                    ->maxLength(120),
                Textarea::make('body')
                    ->label(__('workspaces.ship.body'))
                    ->rows(5),
                Toggle::make('then_push')
                    ->label(fn (): string => __($this->task()?->pr_number === null ? 'workspaces.ship.then_push_pr' : 'workspaces.ship.then_push')),
            ])
            ->modalSubmitActionLabel(__('workspaces.ship.commit'))
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    app(WorkspaceShipService::class)->commit($this->requireTask(), $user, (string) $data['subject'], (string) ($data['body'] ?? ''));
                    Notification::make()->title(__('workspaces.ship.committed'))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                $this->dispatch('argos-workspace-changed');
                if ((bool) ($data['then_push'] ?? false)) {
                    $this->replaceMountedAction('push');
                }
            });
    }

    /**
     * Push the branch. Without a pull request yet: open it, title and
     * description drafted by Claude from all commits; with one: confirm.
     */
    public function pushAction(): Action
    {
        $opens = fn (): bool => $this->task()?->pr_number === null;

        return Action::make('push')
            ->label(fn (): string => __($opens() ? 'workspaces.ship.push_pr' : 'workspaces.ship.push'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color('primary')
            ->visible(fn (): bool => $this->canShip())
            ->modalHeading(fn (): string => __($opens() ? 'workspaces.ship.push_pr_heading' : 'workspaces.ship.push_heading'))
            ->modalWidth(fn (): string => $opens() ? '3xl' : 'md')
            ->fillForm(function () use ($opens): array {
                $task = $this->requireTask();
                try {
                    $pending = app(WorkspaceShipService::class)->pending($task);
                } catch (\Throwable) {
                    $pending = ['changed_files' => 0, 'ahead' => 0];
                }
                $this->shipSummary = trans_choice('workspaces.ship.push_summary', $pending['ahead'], ['count' => $pending['ahead']])
                    .($pending['changed_files'] > 0 ? ' '.trans_choice('workspaces.ship.uncommitted_left', $pending['changed_files'], ['count' => $pending['changed_files']]) : '')
                    .$this->gateNote($task);
                // A new pull request opens at once; Claude's draft follows (loadShipDraft).
                $this->shipDrafting = $opens();

                return ['title' => $task->title, 'body' => '', 'draft' => false];
            })
            ->modalContent(fn (): HtmlString => $this->draftingHint())
            ->modalDescription(fn (): string => $this->shipSummary)
            ->schema([
                TextInput::make('title')
                    ->label(__('workspaces.ship.pr_title'))
                    ->required($opens)
                    ->maxLength(200)
                    ->visible($opens),
                Textarea::make('body')
                    ->label(__('workspaces.ship.pr_body'))
                    ->rows(12)
                    ->visible($opens),
                Toggle::make('draft')
                    ->label(__('workspaces.ship.draft'))
                    ->visible($opens),
            ])
            ->modalSubmitActionLabel(fn (): string => __($opens() ? 'workspaces.ship.push_pr' : 'workspaces.ship.push'))
            ->action(function (array $data): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    $url = app(WorkspaceShipService::class)->push($this->requireTask(), $user, (string) ($data['title'] ?? ''), (string) ($data['body'] ?? ''), (bool) ($data['draft'] ?? false));
                    Notification::make()->title(__('workspaces.ship.shipped', ['url' => $url]))->success()->send();
                } catch (WorkspaceException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                }
                $this->dispatch('argos-workspace-changed');
                $this->refreshStatus();
            });
    }

    /**
     * Fill the open commit or push dialog with Claude's draft — called by the
     * dialog right after it opened, so it never waits for Claude. Fields the
     * person already typed into stay as they are.
     */
    public function loadShipDraft(): void
    {
        $index = array_key_last($this->mountedActions ?? []);
        $name = $index !== null ? ($this->mountedActions[$index]['name'] ?? null) : null;
        $task = $this->task();
        if (! $this->shipDrafting || ! in_array($name, ['commit', 'push'], true) || $task === null) {
            $this->shipDrafting = false;

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $service = app(WorkspaceShipService::class);
        try {
            $draft = $name === 'commit' ? $service->draft($task, $user) : $service->pullRequestDraft($task, $user);
        } catch (\Throwable) {
            $draft = [];
        }
        $this->shipDrafting = false;

        $first = $name === 'commit' ? 'subject' : 'title';
        if (trim((string) ($draft[$first] ?? '')) === '') {
            $this->shipSummary .= ' '.__($name === 'commit' ? 'workspaces.ship.no_draft' : 'workspaces.ship.no_pr_draft');

            return;
        }
        $data = $this->mountedActions[$index]['data'] ?? [];
        $untouched = [$first => $name === 'commit' ? '' : $task->title, 'body' => ''];
        foreach ($untouched as $field => $initial) {
            if (trim((string) ($data[$field] ?? '')) === $initial) {
                $this->mountedActions[$index]['data'][$field] = (string) $draft[$field];
            }
        }
    }

    /** "Claude drafts …" above the dialog's fields, which starts the draft when it appears. */
    private function draftingHint(): HtmlString
    {
        if (! $this->shipDrafting) {
            return new HtmlString('');
        }

        return new HtmlString('<p class="ag-note ag-muted" x-init="$wire.loadShipDraft()" data-testid="ship-drafting"><span class="ag-spin"></span> '.e(__('workspaces.ship.drafting')).'</p>');
    }

    private function canShip(): bool
    {
        return $this->workspace()->status === WorkspaceStatus::Running
            && ($task = $this->task()) !== null
            && (auth()->user()?->can('ship', $task) ?? false);
    }

    /** " Gates: …" for the commit and push dialogs, empty before the first run. */
    private function gateNote(Task $task): string
    {
        $outcome = app(WorkspaceGateService::class)->outcome($task);

        return $outcome === null ? '' : ' '.__($outcome['failed'] !== [] ? 'workspaces.gates.ship_warning' : 'workspaces.gates.ship_summary', ['summary' => $outcome['summary']]);
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

    /** Invite a workspace member to work on the current task. */
    public function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('workspaces.task_actions.invite'))
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => ($task = $this->task()) !== null && (auth()->user()?->can('invite', $task) ?? false))
            ->modalDescription(__('workspaces.task_actions.invite_hint'))
            ->schema([
                Select::make('user_id')
                    ->label(__('workspaces.members.user'))
                    ->options(fn (): array => app(TaskMembershipService::class)->inviteCandidates($this->requireTask()))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $member = User::query()->findOrFail($data['user_id']);
                $this->taskAction(fn (Task $task, User $user) => app(TaskMembershipService::class)->invite($task, $user, $member), __('workspaces.task_actions.invited', ['name' => $member->name]));
            });
    }

    /** Remove a collaborator from the current task. */
    public function uninviteAction(): Action
    {
        return Action::make('uninvite')
            ->label(__('workspaces.task_actions.uninvite'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('workspaces.task_actions.uninvite_heading', ['name' => (string) User::query()->whereKey($arguments['user'] ?? 0)->value('name')]))
            ->modalDescription(__('workspaces.task_actions.uninvite_confirm'))
            ->modalSubmitActionLabel(__('workspaces.task_actions.uninvite'))
            ->visible(fn (): bool => ($task = $this->task()) !== null && (auth()->user()?->can('invite', $task) ?? false))
            ->action(function (array $arguments): void {
                $member = User::query()->findOrFail($arguments['user'] ?? 0);
                $this->taskAction(fn (Task $task, User $user) => app(TaskMembershipService::class)->uninvite($task, $user, $member));
            });
    }

    /** Hand the current task to another member (it moves to their subscription). */
    public function transferAction(): Action
    {
        return Action::make('transfer')
            ->label(__('workspaces.task_actions.transfer'))
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (): bool => ($task = $this->task()) !== null && (auth()->user()?->can('transfer', $task) ?? false))
            ->modalDescription(__('workspaces.task_actions.transfer_hint'))
            ->schema([
                Select::make('user_id')
                    ->label(__('workspaces.task_actions.new_owner'))
                    ->options(fn (): array => app(TaskMembershipService::class)->transferCandidates($this->requireTask()))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $newOwner = User::query()->findOrFail($data['user_id']);
                $this->taskAction(fn (Task $task, User $user) => app(TaskMembershipService::class)->transfer($task, $user, $newOwner), __('workspaces.task_actions.transferred', ['name' => $newOwner->name]));
            });
    }

    /** Link the current task (without a ticket) to an issue of the workspace's tracker. */
    public function linkTicketAction(): Action
    {
        return Action::make('linkTicket')
            ->label(__('workspaces.task_actions.link_ticket'))
            ->icon('heroicon-o-link')
            ->visible(fn (): bool => $this->canChangeTicket())
            ->schema([
                Select::make('number')
                    ->label(__('workspaces.task.ticket'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(WorkspaceIssueService::class)->search($this->workspace(), $search))
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null : (app(WorkspaceIssueService::class)->search($this->workspace(), $value, 200)[$value] ?? '#'.$value))
                    ->required(),
            ])
            ->action(fn (array $data) => $this->taskAction(fn (Task $task, User $user) => app(TaskTicketService::class)->linkTicket($task, $user, (string) $data['number']), __('workspaces.task_actions.ticket_linked')));
    }

    /** Open an issue in the workspace's tracker for the current task. */
    public function createTicketAction(): Action
    {
        return Action::make('createTicket')
            ->label(__('workspaces.task_actions.create_ticket'))
            ->icon('heroicon-o-document-plus')
            ->visible(fn (): bool => $this->canChangeTicket())
            ->fillForm(fn (): array => ['title' => $this->task()?->title])
            ->schema([
                TextInput::make('title')->label(__('workspaces.task_actions.ticket_title'))->required()->maxLength(255),
                Textarea::make('body')->label(__('workspaces.task_actions.ticket_body'))->rows(6),
            ])
            ->action(fn (array $data) => $this->taskAction(fn (Task $task, User $user) => app(TaskTicketService::class)->createTicket($task, $user, (string) $data['title'], (string) ($data['body'] ?? '')), __('workspaces.task_actions.ticket_created')));
    }

    /** A task without a ticket, a tracker to put one in, and the right to change the task. */
    private function canChangeTicket(): bool
    {
        $task = $this->task();

        return $task !== null && $task->external_ref === null
            && (auth()->user()?->can('update', $task) ?? false)
            && app(WorkspaceIssueService::class)->bindingFor($this->workspace()) !== null;
    }

    /** Continue a finished task in a new one (same ticket, fresh branch). */
    public function followUpAction(): Action
    {
        return Action::make('followUp')
            ->label(__('workspaces.task_actions.follow_up'))
            ->icon('heroicon-o-arrow-uturn-right')
            ->visible(fn (): bool => ($task = $this->task()) !== null && $task->status->isFinished()
                && (auth()->user()?->can('create', [Task::class, $this->workspace()]) ?? false))
            ->action(function (): void {
                $this->dispatch('task-follow-up', id: $this->taskId);
            });
    }

    /** Stop working on the current task without merging. */
    public function cancelTaskAction(): Action
    {
        return Action::make('cancelTask')
            ->label(__('workspaces.task_actions.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('warning')
            ->visible(fn (): bool => ($task = $this->task()) !== null && ! $task->status->isFinished() && (auth()->user()?->can('archive', $task) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('workspaces.task_actions.cancel_confirm'))
            ->action(fn () => $this->taskAction(fn (Task $task, User $user) => app(TaskService::class)->cancel($task, $user)));
    }

    /** Delete the current task with its working copy (archiving is the normal way). */
    public function deleteTaskAction(): Action
    {
        return Action::make('deleteTask')
            ->label(__('workspaces.task_actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => ($task = $this->task()) !== null && (auth()->user()?->can('delete', $task) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('workspaces.task_actions.delete_confirm'))
            ->action(function (): void {
                $id = $this->taskId;
                if ($this->taskAction(fn (Task $task, User $user) => app(TaskService::class)->discard($task, $user))) {
                    $this->dispatch('task-archived', id: $id);
                }
            });
    }

    /**
     * Run an action on the current task and report the outcome.
     *
     * @param  callable(Task, User): mixed  $action
     * @return bool whether it went through
     */
    private function taskAction(callable $action, ?string $success = null): bool
    {
        /** @var User $user */
        $user = auth()->user();
        $task = $this->task();
        if ($task === null) {
            return false;
        }
        $done = false;
        try {
            $action($task, $user);
            $done = true;
            if ($success !== null) {
                Notification::make()->title($success)->success()->send();
            }
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
        $this->dispatch('argos-tasks-changed');

        return $done;
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
