<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\ReviewFileView;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Review-mode state of a person: files marked as seen in a task (bound to
 * the state of the file's changes, so a later change unmarks it) and the
 * preferred diff layout.
 */
class ReviewService
{
    public const VIEWS = ['split', 'unified'];

    public function markSeen(Task $task, User $user, string $path, string $fileHash): void
    {
        Gate::forUser($user)->authorize('view', $task->workspace);
        ReviewFileView::query()->updateOrCreate(
            ['task_id' => $task->id, 'user_id' => $user->id, 'path_hash' => sha1($path)],
            ['path' => $path, 'file_hash' => $fileHash],
        );
    }

    public function unmarkSeen(Task $task, User $user, string $path): void
    {
        ReviewFileView::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->where('path_hash', sha1($path))
            ->delete();
    }

    /**
     * Paths seen at their current state.
     *
     * @param  array<string, string>  $hashes  path => current file hash
     * @return list<string>
     */
    public function seen(Task $task, User $user, array $hashes): array
    {
        return ReviewFileView::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->get(['path', 'file_hash'])
            ->filter(fn (ReviewFileView $view): bool => ($hashes[$view->path] ?? null) === $view->file_hash)
            ->pluck('path')
            ->values()
            ->all();
    }

    public function diffView(User $user): string
    {
        $view = $user->ui_prefs['diff_view'] ?? 'split';

        return in_array($view, self::VIEWS, true) ? $view : 'split';
    }

    public function setDiffView(User $user, string $view): void
    {
        if (in_array($view, self::VIEWS, true)) {
            $user->forceFill(['ui_prefs' => [...($user->ui_prefs ?? []), 'diff_view' => $view]])->save();
        }
    }
}
