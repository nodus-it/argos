<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What every part of the workspace page needs: the signed-in person, the
 * workspace (read once per request), its tasks — never one of another
 * workspace — and one way to run an action that may fail.
 *
 * Components using it declare `public string $workspaceId`.
 */
trait InteractsWithWorkspace
{
    private ?Workspace $workspaceModel = null;

    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function workspace(): Workspace
    {
        return $this->workspaceModel ??= Workspace::query()->findOrFail($this->workspaceId);
    }

    /** A task of this workspace (with its workspace loaded), or null. */
    protected function findTask(?string $id): ?Task
    {
        return $id !== null ? Task::query()->with('workspace')->where('workspace_id', $this->workspaceId)->find($id) : null;
    }

    protected function findTaskOrFail(?string $id): Task
    {
        return $this->findTask($id) ?? throw (new ModelNotFoundException)->setModel(Task::class, [$id]);
    }

    /**
     * Run an action; an expected failure becomes a notification instead of
     * an error page. Returns whether it went through.
     */
    protected function attempt(callable $action, bool $warn = false): bool
    {
        try {
            $action();

            return true;
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->status($warn ? 'warning' : 'danger')->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }

        return false;
    }
}
