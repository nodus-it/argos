import { randomBytes } from 'node:crypto';

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/** Minimal ULID generator (Crockford base32, 48-bit time + 80-bit randomness). */
export function ulid(now: number = Date.now()): string {
    let time = '';
    let remaining = now;
    for (let i = 0; i < 10; i++) {
        time = ALPHABET[remaining % 32] + time;
        remaining = Math.floor(remaining / 32);
    }

    const bytes = randomBytes(16);
    let random = '';
    for (let i = 0; i < 16; i++) {
        random += ALPHABET[(bytes[i] ?? 0) % 32];
    }

    return time + random;
}
