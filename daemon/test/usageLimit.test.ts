import { describe, expect, it } from 'vitest';
import { extractResetAt, isUsageLimitMessage, resetsAtFromEpoch } from '../src/usageLimit.js';

describe('usage limit detection', () => {
    it.each([
        "You've hit your limit · resets 9:10pm (UTC)",
        'Claude AI usage limit reached',
        'rate_limit_error: Too many requests',
        '5-hour limit reached',
        'Error 529 overloaded',
    ])('detects %s', (message) => {
        expect(isUsageLimitMessage(message)).toBe(true);
    });

    it('ignores unrelated errors', () => {
        expect(isUsageLimitMessage('ENOENT: no such file')).toBe(false);
    });

    it('extracts ISO timestamps verbatim', () => {
        expect(extractResetAt('limit, resets 2026-05-13T21:10:00Z, sorry')).toBe('2026-05-13T21:10:00Z');
    });

    it('resolves 12h and 24h clocks to the next occurrence in UTC', () => {
        const now = new Date('2026-10-20T15:00:00Z');
        expect(extractResetAt('resets 9:10pm (UTC)', now)).toBe('2026-10-20T21:10:00Z');
        expect(extractResetAt('resets at 21:10 UTC', now)).toBe('2026-10-20T21:10:00Z');
        expect(extractResetAt('resets 9am (UTC)', now)).toBe('2026-10-21T09:00:00Z');
        expect(extractResetAt('resets 12am', now)).toBe('2026-10-21T00:00:00Z');
        expect(extractResetAt('no reset hint', now)).toBeNull();
    });

    it('converts SDK epoch seconds', () => {
        expect(resetsAtFromEpoch(1_792_000_000)).toBe(new Date(1_792_000_000_000).toISOString().replace('.000', ''));
        expect(resetsAtFromEpoch(undefined)).toBeNull();
    });
});
