import { CodedError } from './errors.js';
import { ulid } from './ulid.js';

export type ApprovalKind = 'tool' | 'plan' | 'question';

export interface ApprovalDecision {
    decision: 'allow' | 'deny';
    updatedInput?: Record<string, unknown>;
    message?: string;
    interrupt?: boolean;
    /** "Always allow": apply Claude Code's permission suggestions for the rest of the session. */
    remember?: boolean;
}

interface PendingApproval {
    taskId: string;
    resolve: (decision: ApprovalDecision) => void;
}

export class UnknownApprovalError extends CodedError {
    constructor(approvalId: string) {
        super(404, 'unknown_approval', `Unknown approval ${approvalId}`);
    }
}

/**
 * Holds `canUseTool` calls that wait for a human decision.
 *
 * There is deliberately no timeout: in a shared task a decision may take a
 * while. A request ends when someone decides, when the task is interrupted
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
    open(taskId: string, signal?: AbortSignal, onAbort?: (approvalId: string) => void): { approvalId: string; decision: Promise<ApprovalDecision> } {
        const approvalId = ulid();

        const decision = new Promise<ApprovalDecision>((resolve) => {
            this.pending.set(approvalId, { taskId, resolve });

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

    resolve(taskId: string, approvalId: string, decision: ApprovalDecision): void {
        const entry = this.pending.get(approvalId);
        if (entry === undefined || entry.taskId !== taskId) {
            throw new UnknownApprovalError(approvalId);
        }

        this.pending.delete(approvalId);
        entry.resolve(decision);
    }

    /** Deny (and interrupt) every open request of a task; returns the affected ids. */
    cancelTask(taskId: string, message: string): string[] {
        const cancelled: string[] = [];
        for (const [approvalId, entry] of this.pending) {
            if (entry.taskId !== taskId) {
                continue;
            }
            this.pending.delete(approvalId);
            entry.resolve({ decision: 'deny', message, interrupt: true });
            cancelled.push(approvalId);
        }

        return cancelled;
    }

    has(approvalId: string): boolean {
        return this.pending.has(approvalId);
    }

    countFor(taskId: string): number {
        let count = 0;
        for (const entry of this.pending.values()) {
            if (entry.taskId === taskId) {
                count++;
            }
        }

        return count;
    }
}
