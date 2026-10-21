import { randomUUID } from 'node:crypto';
import type { DaemonEvent, EventBatch, PendingEvent } from './events.js';

/** Acks returned by the manager: highest processed seq per task key. */
export type Acks = Record<string, number>;

/** Delivers one batch; resolves with the manager's acks or throws on failure. */
export type BatchTransport = (batch: EventBatch) => Promise<Acks>;

export interface OutboxOptions {
    flushIntervalMs?: number;
    flushMaxEvents?: number;
    maxBatchEvents?: number;
    maxBufferedPerTask?: number;
    maxBackoffMs?: number;
    now?: () => Date;
    onDeliveryError?: (error: unknown) => void;
}

/** Task key used for workspace-level events (task_id = null). */
export const WORKSPACE_KEY = '_workspace';

/**
 * Stamps events with per-task sequence numbers, batches them and delivers
 * them to the manager until acknowledged.
 *
 * Delivery is at-least-once: every flush re-sends all unacknowledged events
 * (up to `maxBatchEvents`), and the manager drops anything at or below its
 * stored seq. Unacked events are buffered per task; past the limit the
 * oldest streaming deltas are dropped first since the final message carries
 * the same content.
 */
export class Outbox {
    private readonly seqs = new Map<string, number>();
    private readonly buffers = new Map<string, DaemonEvent[]>();
    private timer: NodeJS.Timeout | null = null;
    private inFlight: Promise<void> | null = null;
    private failures = 0;
    private stopped = false;

    private readonly flushIntervalMs: number;
    private readonly flushMaxEvents: number;
    private readonly maxBatchEvents: number;
    private readonly maxBufferedPerTask: number;
    private readonly maxBackoffMs: number;
    private readonly now: () => Date;
    private readonly onDeliveryError: (error: unknown) => void;

    constructor(private readonly transport: BatchTransport, options: OutboxOptions = {}) {
        this.flushIntervalMs = options.flushIntervalMs ?? 75;
        this.flushMaxEvents = options.flushMaxEvents ?? 20;
        this.maxBatchEvents = options.maxBatchEvents ?? 200;
        this.maxBufferedPerTask = options.maxBufferedPerTask ?? 5000;
        this.maxBackoffMs = options.maxBackoffMs ?? 10_000;
        this.now = options.now ?? (() => new Date());
        this.onDeliveryError = options.onDeliveryError ?? (() => {});
    }

    /** Continue numbering after `offset` (the manager's last stored seq). */
    setSeqOffset(taskId: string | null, offset: number): void {
        const key = keyOf(taskId);
        const current = this.seqs.get(key) ?? 0;
        this.seqs.set(key, Math.max(current, offset));
    }

    push(taskId: string | null, event: PendingEvent): DaemonEvent {
        const key = keyOf(taskId);
        const seq = (this.seqs.get(key) ?? 0) + 1;
        this.seqs.set(key, seq);

        const stamped: DaemonEvent = {
            task_id: taskId,
            seq,
            ts: this.now().toISOString(),
            type: event.type,
            data: event.data,
        };

        const buffer = this.buffers.get(key) ?? [];
        buffer.push(stamped);
        this.buffers.set(key, buffer);
        this.enforceLimit(buffer);

        if (this.bufferedCount() >= this.flushMaxEvents) {
            this.schedule(0);
        } else {
            this.schedule(this.flushIntervalMs);
        }

        return stamped;
    }

    /** Number of events not yet acknowledged. */
    bufferedCount(): number {
        let total = 0;
        for (const buffer of this.buffers.values()) {
            total += buffer.length;
        }

        return total;
    }

    /** Flush now and wait until the current delivery attempt finished. */
    async flush(): Promise<void> {
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        if (this.inFlight !== null) {
            await this.inFlight;
        }
        if (this.bufferedCount() === 0) {
            return;
        }

        this.inFlight = this.deliver().finally(() => {
            this.inFlight = null;
        });
        await this.inFlight;
    }

    /** Stop scheduling; pending events stay buffered. */
    stop(): void {
        this.stopped = true;
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    private schedule(delayMs: number): void {
        if (this.stopped) {
            return;
        }
        if (this.timer !== null) {
            // A pending timer wins over a later one, and a retry backoff is
            // never cut short by new events.
            if (delayMs > 0 || this.failures > 0) {
                return;
            }
            clearTimeout(this.timer);
        }

        this.timer = setTimeout(() => {
            this.timer = null;
            void this.flush();
        }, delayMs);
    }

    private async deliver(): Promise<void> {
        const events = this.collectBatch();
        if (events.length === 0) {
            return;
        }

        try {
            const acks = await this.transport({ batch_id: randomUUID(), events });
            this.applyAcks(acks);
            this.failures = 0;
        } catch (error) {
            this.failures++;
            this.onDeliveryError(error);
            const backoff = Math.min(this.maxBackoffMs, 250 * 2 ** (this.failures - 1));
            this.schedule(backoff);

            return;
        }

        if (this.bufferedCount() > 0) {
            this.schedule(0);
        }
    }

    private collectBatch(): DaemonEvent[] {
        const all: DaemonEvent[] = [];
        for (const buffer of this.buffers.values()) {
            all.push(...buffer);
        }
        all.sort((a, b) => a.ts.localeCompare(b.ts) || a.seq - b.seq);

        return all.slice(0, this.maxBatchEvents);
    }

    private applyAcks(acks: Acks): void {
        for (const [key, ackedSeq] of Object.entries(acks)) {
            const buffer = this.buffers.get(key);
            if (buffer === undefined) {
                continue;
            }
            const remaining = buffer.filter((event) => event.seq > ackedSeq);
            if (remaining.length === 0) {
                this.buffers.delete(key);
            } else {
                this.buffers.set(key, remaining);
            }
        }
    }

    private enforceLimit(buffer: DaemonEvent[]): void {
        while (buffer.length > this.maxBufferedPerTask) {
            const deltaIndex = buffer.findIndex((event) => event.type.endsWith('.delta'));
            buffer.splice(deltaIndex === -1 ? 0 : deltaIndex, 1);
        }
    }
}

function keyOf(taskId: string | null): string {
    return taskId ?? WORKSPACE_KEY;
}
