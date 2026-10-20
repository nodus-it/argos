<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something in one of the user's workspaces changed or wants attention.
 * Drives the dashboard overview and, when that workspace is not visible,
 * browser notifications and the tab-title counter.
 *
 * kind: summary (status changed) | approval | turn | limit | error | ship
 */
class UserWorkspaceSignal implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  array{workspace_id: string, workspace_name: string, thread_id: string|null, kind: string, title: string, body: string, url: string}  $signal
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $signal,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'workspace.signal';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->signal;
    }
}
