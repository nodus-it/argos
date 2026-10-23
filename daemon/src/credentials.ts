import { createServer, type Server } from 'node:http';

export interface RepoCredential {
    username: string;
    password: string;
}

export type CredentialFetcher = () => Promise<RepoCredential>;

/**
 * Caches the repository credential the manager hands out. The token lives in
 * memory only and is refreshed after `ttlMs`, so OAuth refreshes on the
 * manager side reach git without a restart.
 */
export class RepoCredentialCache {
    private cached: { credential: RepoCredential; until: number } | null = null;

    constructor(
        private readonly fetcher: CredentialFetcher,
        private readonly ttlMs = 5 * 60_000,
        private readonly now: () => number = Date.now,
    ) {}

    async get(): Promise<RepoCredential> {
        if (this.cached !== null && this.cached.until > this.now()) {
            return this.cached.credential;
        }
        const credential = await this.fetcher();
        this.cached = { credential, until: this.now() + this.ttlMs };

        return credential;
    }

    invalidate(): void {
        this.cached = null;
    }
}

/** Fetch the credential from the manager's internal endpoint. */
export function managerCredentialFetcher(url: string, daemonToken: string): CredentialFetcher {
    return async () => {
        const response = await fetch(url, { headers: { Authorization: `Bearer ${daemonToken}`, Accept: 'application/json' } });
        if (!response.ok) {
            throw new Error(`repo-credential request failed with ${response.status}`);
        }
        const body = (await response.json()) as Partial<RepoCredential>;
        if (typeof body.username !== 'string' || typeof body.password !== 'string') {
            throw new Error('repo-credential response malformed');
        }

        return { username: body.username, password: body.password };
    };
}

/**
 * Loopback-only endpoint for the git credential helper script. Anything in
 * the container can reach it; that is accepted by design (see the
 * "Security" section of docs/WORKSPACES.md): the credential is scoped to
 * this workspace's repository.
 */
export function startCredentialServer(cache: RepoCredentialCache, port: number, host = '127.0.0.1'): Promise<Server> {
    const server = createServer((request, response) => {
        if (request.method !== 'POST' || request.url !== '/git-credential') {
            response.writeHead(404).end();

            return;
        }
        cache
            .get()
            .then((credential) => {
                response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(credential));
            })
            .catch(() => {
                cache.invalidate();
                response.writeHead(502).end();
            });
    });

    return new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(port, host, () => resolve(server));
    });
}
