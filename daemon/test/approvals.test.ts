import { describe, expect, it } from 'vitest';
import { ApprovalRegistry, UnknownApprovalError } from '../src/approvals.js';

describe('ApprovalRegistry', () => {
    it('resolves a pending request with the decision', async () => {
        const registry = new ApprovalRegistry();
        const { approvalId, decision } = registry.open('t1');
        expect(registry.countFor('t1')).toBe(1);
        registry.resolve('t1', approvalId, { decision: 'allow' });
        await expect(decision).resolves.toEqual({ decision: 'allow' });
        expect(registry.has(approvalId)).toBe(false);
    });

    it('rejects unknown ids and ids of other tasks', () => {
        const registry = new ApprovalRegistry();
        const { approvalId } = registry.open('t1');
        expect(() => registry.resolve('t1', 'nope', { decision: 'allow' })).toThrow(UnknownApprovalError);
        expect(() => registry.resolve('t2', approvalId, { decision: 'allow' })).toThrow(UnknownApprovalError);
    });

    it('cancels all requests of one task only', async () => {
        const registry = new ApprovalRegistry();
        const a = registry.open('t1');
        const b = registry.open('t1');
        const c = registry.open('t2');
        expect(registry.cancelTask('t1', 'Interrupted').sort()).toEqual([a.approvalId, b.approvalId].sort());
        await expect(a.decision).resolves.toEqual({ decision: 'deny', message: 'Interrupted', interrupt: true });
        expect(registry.has(c.approvalId)).toBe(true);
    });

    it('denies and reports when the SDK aborts', async () => {
        const registry = new ApprovalRegistry();
        const controller = new AbortController();
        const aborted: string[] = [];
        const { approvalId, decision } = registry.open('t1', controller.signal, (id) => aborted.push(id));
        controller.abort();
        await expect(decision).resolves.toMatchObject({ decision: 'deny' });
        expect(aborted).toEqual([approvalId]);
    });

    it('does not report an abort after the request was decided', () => {
        const registry = new ApprovalRegistry();
        const controller = new AbortController();
        const aborted: string[] = [];
        const { approvalId } = registry.open('t1', controller.signal, (id) => aborted.push(id));
        registry.resolve('t1', approvalId, { decision: 'allow' });
        controller.abort();
        expect(aborted).toEqual([]);
    });
});
