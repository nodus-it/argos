import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EventBatch } from '../src/events.js';
import { Outbox, WORKSPACE_KEY, type Acks } from '../src/outbox.js';

const ackAll = (batch: EventBatch): Acks => {
    const acks: Acks = {};
    for (const event of batch.events) {
        const key = event.task_id ?? WORKSPACE_KEY;
        acks[key] = Math.max(acks[key] ?? 0, event.seq);
    }

    return acks;
};

describe('Outbox', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('numbers events per task starting after the offset', () => {
        const outbox = new Outbox(async () => ({}));
        outbox.setSeqOffset('t1', 41);
        expect(outbox.push('t1', { type: 'status', data: {} }).seq).toBe(42);
        expect(outbox.push('t1', { type: 'status', data: {} }).seq).toBe(43);
        expect(outbox.push('t2', { type: 'status', data: {} }).seq).toBe(1);
        expect(outbox.push(null, { type: 'status', data: {} }).seq).toBe(1);
        outbox.stop();
    });

    it('batches by time and forgets acknowledged events', async () => {
        const batches: EventBatch[] = [];
        const outbox = new Outbox(async (batch) => {
            batches.push(batch);

            return ackAll(batch);
        });
        outbox.push('t1', { type: 'assistant.delta', data: { text: 'a' } });
        outbox.push('t1', { type: 'assistant.delta', data: { text: 'b' } });
        expect(batches).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(80);
        expect(batches).toHaveLength(1);
        expect(batches[0]!.events.map((e) => e.seq)).toEqual([1, 2]);
        expect(outbox.bufferedCount()).toBe(0);
        outbox.stop();
    });

    it('flushes immediately once the size threshold is reached', async () => {
        const batches: EventBatch[] = [];
        const outbox = new Outbox(async (batch) => {
            batches.push(batch);

            return ackAll(batch);
        }, { flushMaxEvents: 3 });
        for (let i = 0; i < 3; i++) {
            outbox.push('t1', { type: 'status', data: { i } });
        }
        await vi.advanceTimersByTimeAsync(0);
        expect(batches).toHaveLength(1);
        outbox.stop();
    });

    it('retries with backoff and re-sends unacknowledged events', async () => {
        let calls = 0;
        const batches: EventBatch[] = [];
        const outbox = new Outbox(async (batch) => {
            calls++;
            batches.push(batch);
            if (calls === 1) {
                throw new Error('down');
            }

            return ackAll(batch);
        });
        outbox.push('t1', { type: 'status', data: {} });
        await vi.advanceTimersByTimeAsync(80);
        expect(calls).toBe(1);
        expect(outbox.bufferedCount()).toBe(1);

        await vi.advanceTimersByTimeAsync(260);
        expect(calls).toBe(2);
        expect(batches[1]!.events[0]!.seq).toBe(1);
        expect(outbox.bufferedCount()).toBe(0);
        outbox.stop();
    });

    it('does not cut the retry backoff short when more events arrive', async () => {
        let calls = 0;
        const outbox = new Outbox(async () => {
            calls++;
            throw new Error('down');
        }, { flushMaxEvents: 3, maxBackoffMs: 10_000 });
        for (let i = 0; i < 3; i++) {
            outbox.push('t1', { type: 'assistant.delta', data: { i } });
        }
        await vi.advanceTimersByTimeAsync(0);
        expect(calls).toBe(1);

        // Still above the size threshold, but within the 250 ms backoff.
        for (let i = 0; i < 10; i++) {
            outbox.push('t1', { type: 'assistant.delta', data: { i } });
            await vi.advanceTimersByTimeAsync(10);
        }
        expect(calls).toBe(1);

        await vi.advanceTimersByTimeAsync(200);
        expect(calls).toBe(2);
        outbox.stop();
    });

    it('keeps events the manager did not acknowledge yet', async () => {
        const outbox = new Outbox(async () => ({ t1: 1 }));
        outbox.push('t1', { type: 'status', data: {} });
        outbox.push('t1', { type: 'status', data: {} });
        await outbox.flush();
        expect(outbox.bufferedCount()).toBe(1);
        outbox.stop();
    });

    it('drops oldest deltas first when the buffer overflows', () => {
        const outbox = new Outbox(async () => {
            throw new Error('down');
        }, { maxBufferedPerTask: 3 });
        outbox.stop();
        outbox.push('t1', { type: 'tool.started', data: {} });
        outbox.push('t1', { type: 'assistant.delta', data: { n: 1 } });
        outbox.push('t1', { type: 'assistant.delta', data: { n: 2 } });
        outbox.push('t1', { type: 'assistant.message', data: {} });
        expect(outbox.bufferedCount()).toBe(3);
    });
});
