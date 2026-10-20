<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadRead;
use App\Models\User;
use Illuminate\Support\Collection;

/** Per-member read position of threads, for unread counters. */
class ThreadReadService
{
    public function markRead(Thread $thread, User $user): void
    {
        $latest = (int) ThreadEvent::query()->where('thread_id', $thread->id)->max('id');

        $read = ThreadRead::query()->firstOrNew(['thread_id' => $thread->id, 'user_id' => $user->id]);
        if ($read->exists && $read->last_read_event_id >= $latest) {
            return;
        }
        $read->last_read_event_id = $latest;
        $read->save();
    }

    /**
     * Unread history entries per thread id (assistant output, approvals,
     * errors and other people's messages — never one's own).
     *
     * @param  Collection<int, Thread>  $threads
     * @return array<string, int>
     */
    public function unreadCounts(Collection $threads, User $user): array
    {
        if ($threads->isEmpty()) {
            return [];
        }

        $reads = ThreadRead::query()
            ->where('user_id', $user->id)
            ->whereIn('thread_id', $threads->pluck('id'))
            ->pluck('last_read_event_id', 'thread_id');

        $counts = [];
        foreach ($threads as $thread) {
            $counts[$thread->id] = ThreadEvent::query()
                ->where('thread_id', $thread->id)
                ->where('id', '>', (int) ($reads[$thread->id] ?? 0))
                ->where(fn ($q) => $q->whereNull('author_user_id')->orWhere('author_user_id', '!=', $user->id))
                ->whereNotIn('type', ['tool_result', 'thinking', 'status_changed'])
                ->count();
        }

        return $counts;
    }
}
