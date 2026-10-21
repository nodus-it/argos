<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\WorkspaceRole;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the people of a task that something needs them: an approval, a
 * finished long turn, a usage limit, an error, a shipment.
 *
 * The task's owner and collaborators (except the person who caused it) get a
 * realtime signal; the browser decides whether to show it (only when that
 * task is not in view). Without a task, the workspace's admins and members
 * are told. Kinds that need action also land in the notification bell.
 * Users can switch kinds off in their profile.
 */
class WorkspaceNotifier
{
    /** Kinds that also persist in the bell. */
    private const BELL_KINDS = ['approval', 'limit', 'error', 'ship', 'auto', 'invite', 'ticket'];

    public function notify(Workspace $workspace, ?Task $task, string $kind, string $title, string $body = '', ?int $exceptUserId = null): void
    {
        $url = $workspace->url($task);

        foreach ($this->recipients($workspace, $task) as $user) {
            if ($user->id === $exceptUserId || ! $user->wantsNotification($kind)) {
                continue;
            }

            $this->signal($user, $workspace, $task, $kind, $title, $body, $url);

            if (in_array($kind, self::BELL_KINDS, true)) {
                Notification::make()
                    ->title($title)
                    ->body($body !== '' ? $body : $workspace->name)
                    ->icon($kind === 'error' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-command-line')
                    ->actions([Action::make('open')->label(__('workspaces.notifications.open'))->url($url)])
                    ->sendToDatabase($user);
            }
        }
    }

    /** Quiet refresh signal for overviews (task status changed). */
    public function summaryChanged(Workspace $workspace, ?Task $task): void
    {
        foreach ($workspace->users()->get() as $user) {
            $this->signal($user, $workspace, $task, 'summary', '', '', $workspace->url());
        }
    }

    /** @return iterable<User> */
    private function recipients(Workspace $workspace, ?Task $task): iterable
    {
        if ($task !== null) {
            return collect([$task->owner])->filter()->merge($task->collaborators()->get())->unique('id');
        }

        return $workspace->users()
            ->wherePivotIn('role', [WorkspaceRole::Admin->value, WorkspaceRole::Member->value])
            ->get();
    }

    private function signal(User $user, Workspace $workspace, ?Task $task, string $kind, string $title, string $body, string $url): void
    {
        try {
            broadcast(new UserWorkspaceSignal($user->id, [
                'workspace_id' => $workspace->id,
                'workspace_name' => $workspace->name,
                'task_id' => $task?->id,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ]));
        } catch (Throwable $e) {
            Log::channel('argos')->info('Workspace signal failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
