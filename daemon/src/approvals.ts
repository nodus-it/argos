import { ulid } from './ulid.js';

export type ApprovalKind = 'tool' | 'plan' | 'question';

export interface ApprovalDecision {
    decision: 'allow' | 'deny';
    updatedInput?: Record<string, unknown>;
    message?: string;
    interrupt?: boolean;
}

interface PendingApproval {
    threadId: string;
    resolve: (decision: ApprovalDecision) => void;
}

export class UnknownApprovalError extends Error {
    constructor(approvalId: string) {
        super(`Unknown approval ${approvalId}`);
    }
}

/**
 * Holds `canUseTool` calls that wait for a human decision.
 *
 * There is deliberately no timeout: in a shared thread a decision may take a
 * while. A request ends when someone decides, when the thread is interrupted
 * or closed, or when the SDK aborts it.
 */
export class ApprovalRegistry {
    private readonly pending = new Map<string, PendingApproval>();

    static kindFor(toolName: string): ApprovalKind {
        if (toolName === 'AskUserQuestion') {
            return 'question';
        }
        if (toolName === 'ExitPlanMode') {
            return 'plan';
        }

        return 'tool';
    }

    /** Register a request; the promise settles with the human decision. */
    open(threadId: string, signal?: AbortSignal, onAbort?: (approvalId: string) => void): { approvalId: string; decision: Promise<ApprovalDecision> } {
        const approvalId = ulid();

        const decision = new Promise<ApprovalDecision>((resolve) => {
            this.pending.set(approvalId, { threadId, resolve });

            signal?.addEventListener(
                'abort',
                () => {
                    if (this.pending.delete(approvalId)) {
                        onAbort?.(approvalId);
                        resolve({ decision: 'deny', message: 'Request aborted', interrupt: false });
                    }
                },
                { once: true },
            );
        });

        return { approvalId, decision };
    }

    resolve(threadId: string, approvalId: string, decision: ApprovalDecision): void {
        const entry = this.pending.get(approvalId);
        if (entry === undefined || entry.threadId !== threadId) {
            throw new UnknownApprovalError(approvalId);
        }

        this.pending.delete(approvalId);
        entry.resolve(decision);
    }

    /** Deny every open request of a thread; returns the affected ids. */
    cancelThread(threadId: string, message: string, interrupt: boolean): string[] {
        const cancelled: string[] = [];
        for (const [approvalId, entry] of this.pending) {
            if (entry.threadId !== threadId) {
                continue;
            }
            this.pending.delete(approvalId);
            entry.resolve({ decision: 'deny', message, interrupt });
            cancelled.push(approvalId);
        }

        return cancelled;
    }

    has(approvalId: string): boolean {
        return this.pending.has(approvalId);
    }

    countFor(threadId: string): number {
        let count = 0;
        for (const entry of this.pending.values()) {
            if (entry.threadId === threadId) {
                count++;
            }
        }

        return count;
    }
}
