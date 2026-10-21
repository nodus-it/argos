<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live updates of a workspace's tasks to every member's browser:
 * streaming deltas, newly persisted history entries and status changes.
 *
 * Sent immediately (no queue hop) so token streaming stays smooth. Payloads
 * are kept below the Pusher protocol's ~10 KB message limit by
 * TaskStreamPublisher, which truncates and splits.
 */
class TaskStream implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $taskId,
        public readonly array $events,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('workspace.'.$this->workspaceId);
    }

    public function broadcastAs(): string
    {
        return 'task.stream';
    }

    /**
     * @return array{task_id: string, events: list<array<string, mixed>>}
     */
    public function broadcastWith(): array
    {
        return ['task_id' => $this->taskId, 'events' => $this->events];
    }
}
