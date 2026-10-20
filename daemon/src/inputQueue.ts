/**
 * A push queue exposed as a single-consumer AsyncIterable.
 *
 * The SDK's streaming-input mode pulls user messages from an AsyncIterable for
 * the lifetime of the session. Pushing into this queue is how follow-up
 * messages reach a running session; `onTake` fires the moment the SDK pulls a
 * message, which is when the manager may show it as accepted.
 */
export class InputQueue<T> {
    private readonly items: T[] = [];
    private waiter: ((result: IteratorResult<T>) => void) | null = null;
    private closed = false;
    private iterating = false;

    constructor(private readonly onTake: (item: T) => void = () => {}) {}

    get isClosed(): boolean {
        return this.closed;
    }

    get size(): number {
        return this.items.length;
    }

    push(item: T): void {
        if (this.closed) {
            throw new Error('InputQueue is closed');
        }

        if (this.waiter !== null) {
            const resolve = this.waiter;
            this.waiter = null;
            this.onTake(item);
            resolve({ value: item, done: false });

            return;
        }

        this.items.push(item);
    }

    /** End the iterable. Items still queued are dropped. */
    close(): void {
        this.end();
        this.items.length = 0;
    }

    /**
     * Drain queued (not yet taken) items, e.g. to hand them to the
     * replacement query after an interrupt ended the previous one.
     */
    drain(): T[] {
        return this.items.splice(0, this.items.length);
    }

    private end(): void {
        this.closed = true;
        if (this.waiter !== null) {
            const resolve = this.waiter;
            this.waiter = null;
            resolve({ value: undefined, done: true });
        }
    }

    iterable(): AsyncIterable<T> {
        if (this.iterating) {
            throw new Error('InputQueue supports a single consumer');
        }
        this.iterating = true;

        return {
            [Symbol.asyncIterator]: () => ({
                next: (): Promise<IteratorResult<T>> => {
                    const item = this.items.shift();
                    if (item !== undefined) {
                        this.onTake(item);

                        return Promise.resolve({ value: item, done: false });
                    }
                    if (this.closed) {
                        return Promise.resolve({ value: undefined, done: true });
                    }

                    return new Promise((resolve) => {
                        this.waiter = resolve;
                    });
                },
                // The consumer stopped (e.g. the query ended): no more takes,
                // but untaken items stay available to `drain()`.
                return: (): Promise<IteratorResult<T>> => {
                    this.end();

                    return Promise.resolve({ value: undefined, done: true });
                },
            }),
        };
    }
}
