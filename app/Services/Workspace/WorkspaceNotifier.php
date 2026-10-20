<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\WorkspaceRole;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells workspace members that something needs them: an approval, a
 * finished long turn, a usage limit, an error, a shipment.
 *
 * Every operating member (except the person who caused it) gets a realtime
 * signal; the browser decides whether to show it (only when that
 * workspace is not in view). Kinds that need action also land in the
 * notification bell. Users can switch kinds off in their profile.
 */
class WorkspaceNotifier
{
    /** Kinds that also persist in the bell. */
    private const BELL_KINDS = ['approval', 'limit', 'error', 'ship'];

    public function notify(Workspace $workspace, ?Thread $thread, string $kind, string $title, string $body = '', ?int $exceptUserId = null): void
    {
        $url = $workspace->url($thread);

        foreach ($this->operators($workspace) as $user) {
            if ($user->id === $exceptUserId || ! $user->wantsNotification($kind)) {
                continue;
            }

            $this->signal($user, $workspace, $thread, $kind, $title, $body, $url);

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

    /** Quiet refresh signal for overviews (thread status changed). */
    public function summaryChanged(Workspace $workspace, ?Thread $thread): void
    {
        foreach ($workspace->users()->get() as $user) {
            $this->signal($user, $workspace, $thread, 'summary', '', '', $workspace->url());
        }
    }

    /** @return iterable<User> */
    private function operators(Workspace $workspace): iterable
    {
        return $workspace->users()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Editor->value])
            ->get();
    }

    private function signal(User $user, Workspace $workspace, ?Thread $thread, string $kind, string $title, string $body, string $url): void
    {
        try {
            broadcast(new UserWorkspaceSignal($user->id, [
                'workspace_id' => $workspace->id,
                'workspace_name' => $workspace->name,
                'thread_id' => $thread?->id,
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
