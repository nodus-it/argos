/**
 * Usage/rate-limit detection for Claude subscription sessions.
 *
 * Primary signal is the SDK's structured `rate_limit_event` (status
 * `rejected`, `resetsAt` in epoch seconds). The text heuristics (ported
 * from the former Bash worker) cover error messages and results that only
 * carry prose.
 */

const LIMIT_PATTERN =
    /rate.?limit|usage.?limit|message.?limit|weekly.?limit|[0-9]+.?(hour|h).?limit|hit (your|the) .*limit|reached (your|the) .*limit|quota|credit.?balance|overload|too.?many.?request|529/i;

export function isUsageLimitMessage(message: string): boolean {
    return LIMIT_PATTERN.test(message);
}

const AUTH_PATTERN = /authentication_error|oauth token (?:has )?expired|invalid (?:x-)?api.?key|invalid bearer token|token (?:has been )?revoked|please run \/login|api error: 401\b/i;

/**
 * Whether Claude refused the subscription token (expired, revoked, wrong):
 * nothing works on this agent until its owner adds a new token.
 */
export function isAuthFailureMessage(message: string): boolean {
    return AUTH_PATTERN.test(message);
}

/** Convert the SDK's `resetsAt` (epoch seconds) to ISO-8601. */
export function resetsAtFromEpoch(resetsAt: number | undefined): string | null {
    if (resetsAt === undefined || !Number.isFinite(resetsAt) || resetsAt <= 0) {
        return null;
    }

    // Defensive: accept milliseconds too.
    const ms = resetsAt > 1e12 ? resetsAt : resetsAt * 1000;

    return new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/**
 * Parse a reset timestamp out of a limit message: native ISO, or a clock-only
 * "resets 9:10pm (UTC)" / "resets at 21:10 UTC" form (today if still ahead,
 * otherwise tomorrow, in UTC).
 */
export function extractResetAt(message: string, now: Date = new Date()): string | null {
    const iso = message.match(/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[Z+][^, ]*/);
    if (iso !== null) {
        return iso[0];
    }

    const clock = message.match(/resets?(?: at)? ([0-9]{1,2})(?::([0-9]{2}))? ?(am|pm)?/i);
    if (clock === null) {
        return null;
    }

    let hour = Number(clock[1]);
    const minute = Number(clock[2] ?? '0');
    const ampm = (clock[3] ?? '').toLowerCase();
    if (ampm === 'pm' && hour < 12) {
        hour += 12;
    } else if (ampm === 'am' && hour === 12) {
        hour = 0;
    }
    if (hour > 23 || minute > 59) {
        return null;
    }

    const candidate = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), hour, minute, 0));
    if (candidate.getTime() <= now.getTime()) {
        candidate.setUTCDate(candidate.getUTCDate() + 1);
    }

    return candidate.toISOString().replace(/\.\d{3}Z$/, 'Z');
}
