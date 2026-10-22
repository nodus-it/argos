/**
 * Wire types for events the daemon reports to the Argos manager.
 *
 * Every event belongs to a task (or to the workspace when `task_id` is
 * null) and carries a per-task monotonic `seq` so the manager can
 * de-duplicate retried batches.
 */

export type DaemonEventType =
    | 'session.started'
    | 'commands.updated'
    | 'status'
    | 'user.accepted'
    | 'assistant.delta'
    | 'thinking.delta'
    | 'assistant.message'
    | 'thinking'
    | 'tool.started'
    | 'tool.result'
    | 'approval.requested'
    | 'approval.cancelled'
    | 'turn.completed'
    | 'usage_limit'
    | 'error'
    | 'gates.started'
    | 'gates.completed'
    | 'gates.failed'
    | 'gates.baseline'
    | 'checkpoint.created'
    | 'prepare.step'
    | 'prepare.completed'
    | 'prepare.failed';

export type TaskState = 'starting' | 'idle' | 'running' | 'waiting_input' | 'closed';

/** An event before the outbox stamped it with a sequence number. */
export interface PendingEvent {
    type: DaemonEventType;
    data: Record<string, unknown>;
}

export interface DaemonEvent extends PendingEvent {
    task_id: string | null;
    seq: number;
    ts: string;
}

export interface EventBatch {
    batch_id: string;
    events: DaemonEvent[];
}

/** Where a task session sends its events. */
export type EventSink = (event: PendingEvent) => void;

/** Upper bound for tool input/output payloads relayed to the manager. */
const MAX_TOOL_PAYLOAD_BYTES = 64 * 1024;

/**
 * Truncate a string to at most `maxBytes` UTF-8 bytes without splitting a
 * multi-byte character.
 */
export function truncateUtf8(value: string, maxBytes: number = MAX_TOOL_PAYLOAD_BYTES): { text: string; truncated: boolean } {
    const buffer = Buffer.from(value, 'utf8');
    if (buffer.byteLength <= maxBytes) {
        return { text: value, truncated: false };
    }

    let end = maxBytes;
    // Step back over UTF-8 continuation bytes (10xxxxxx) so we cut on a boundary.
    while (end > 0 && ((buffer[end] ?? 0) & 0xc0) === 0x80) {
        end--;
    }

    return { text: buffer.subarray(0, end).toString('utf8'), truncated: true };
}
