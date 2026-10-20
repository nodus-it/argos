import { describe, expect, it } from 'vitest';
import { InputQueue } from '../src/inputQueue.js';

describe('InputQueue', () => {
    it('yields pushed items in order and reports each take', async () => {
        const taken: number[] = [];
        const queue = new InputQueue<number>((n) => taken.push(n));
        queue.push(1);
        queue.push(2);
        const iterator = queue.iterable()[Symbol.asyncIterator]();

        expect(taken).toEqual([]);
        expect((await iterator.next()).value).toBe(1);
        expect(taken).toEqual([1]);
        const pending = iterator.next();
        expect((await pending).value).toBe(2);

        const waiting = iterator.next();
        queue.push(3);
        expect((await waiting).value).toBe(3);
        expect(taken).toEqual([1, 2, 3]);
    });

    it('ends a waiting consumer on close and rejects pushes afterwards', async () => {
        const queue = new InputQueue<string>();
        const iterator = queue.iterable()[Symbol.asyncIterator]();
        const waiting = iterator.next();
        queue.close();
        expect(await waiting).toEqual({ value: undefined, done: true });
        expect(() => queue.push('x')).toThrow('closed');
        expect(queue.isClosed).toBe(true);
    });

    it('drains items that were never taken', () => {
        const queue = new InputQueue<string>();
        queue.push('a');
        queue.push('b');
        expect(queue.drain()).toEqual(['a', 'b']);
        expect(queue.size).toBe(0);
    });

    it('keeps untaken items for drain() when the consumer stops', async () => {
        const queue = new InputQueue<string>();
        const iterator = queue.iterable()[Symbol.asyncIterator]();
        queue.push('a');
        queue.push('b');
        expect((await iterator.next()).value).toBe('a');
        await iterator.return?.();
        expect(queue.isClosed).toBe(true);
        expect(queue.drain()).toEqual(['b']);
    });

    it('allows only one consumer', () => {
        const queue = new InputQueue<string>();
        queue.iterable();
        expect(() => queue.iterable()).toThrow('single consumer');
    });
});
