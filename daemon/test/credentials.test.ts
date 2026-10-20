import { describe, expect, it } from 'vitest';
import { RepoCredentialCache, startCredentialServer } from '../src/credentials.js';

describe('RepoCredentialCache', () => {
    it('caches until the TTL expires', async () => {
        let calls = 0;
        let now = 0;
        const cache = new RepoCredentialCache(async () => ({ username: 'x-access-token', password: `t${++calls}` }), 1000, () => now);
        expect((await cache.get()).password).toBe('t1');
        now = 999;
        expect((await cache.get()).password).toBe('t1');
        now = 1001;
        expect((await cache.get()).password).toBe('t2');
    });

    it('serves the credential on the loopback endpoint', async () => {
        const cache = new RepoCredentialCache(async () => ({ username: 'oauth2', password: 'secret' }));
        const server = await startCredentialServer(cache, 0);
        const address = server.address();
        const port = typeof address === 'object' && address !== null ? address.port : 0;
        try {
            const ok = await fetch(`http://127.0.0.1:${port}/git-credential`, { method: 'POST' });
            expect(await ok.json()).toEqual({ username: 'oauth2', password: 'secret' });
            expect((await fetch(`http://127.0.0.1:${port}/other`)).status).toBe(404);
        } finally {
            server.close();
        }
    });

    it('answers 502 when the manager cannot be reached', async () => {
        const cache = new RepoCredentialCache(async () => {
            throw new Error('down');
        });
        const server = await startCredentialServer(cache, 0);
        const address = server.address();
        const port = typeof address === 'object' && address !== null ? address.port : 0;
        try {
            expect((await fetch(`http://127.0.0.1:${port}/git-credential`, { method: 'POST' })).status).toBe(502);
        } finally {
            server.close();
        }
    });
});
