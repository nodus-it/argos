<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages\Concerns;

use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\TaskMembershipService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\TaskTicketService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceIssueService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The workspace page's task actions (the ⋯ menu and avatars): invite and
 * remove people, hand over, link or create the ticket, follow up, cancel
 * and delete — each through its service, failures as notifications.
 */
trait ManagesTask
{
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
}
