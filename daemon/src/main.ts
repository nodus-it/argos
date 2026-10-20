import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { query } from '@anthropic-ai/claude-agent-sdk';
import { managerCredentialFetcher, RepoCredentialCache, startCredentialServer } from './credentials.js';
import { buildApp } from './http.js';
import { Outbox, type Acks } from './outbox.js';
import type { SdkMessageLike } from './normalize.js';
import type { QueryFactory, QueryLike } from './threadSession.js';

function required(name: string): string {
    const value = process.env[name];
    if (value === undefined || value === '') {
        throw new Error(`${name} must be set`);
    }

    return value;
}

function sdkVersion(): string | undefined {
    try {
        // The package does not export its package.json; resolve the entry
        // point and read the manifest next to it.
        const require = createRequire(import.meta.url);
        const entry = require.resolve('@anthropic-ai/claude-agent-sdk');

        return (JSON.parse(readFileSync(join(dirname(entry), 'package.json'), 'utf8')) as { version?: string }).version;
    } catch {
        return undefined;
    }
}

function claudeVersion(bin: string | undefined): string | undefined {
    try {
        return execFileSync(bin ?? 'claude', ['--version'], { encoding: 'utf8', timeout: 10_000 }).trim();
    } catch {
        return undefined;
    }
}

async function main(): Promise<void> {
    const daemonToken = required('ARGOS_DAEMON_TOKEN');
    const eventsUrl = required('ARGOS_EVENTS_URL');
    const credentialUrl = required('ARGOS_REPO_CREDENTIAL_URL');
    const port = Number(process.env.ARGOS_DAEMON_PORT ?? '7777');
    const credentialPort = Number(process.env.ARGOS_CREDENTIAL_PORT ?? '7778');
    const claudeBin = process.env.ARGOS_CLAUDE_BIN || undefined;

    const outbox = new Outbox(
        async (batch) => {
            const response = await fetch(eventsUrl, {
                method: 'POST',
                headers: { Authorization: `Bearer ${daemonToken}`, 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(batch),
                signal: AbortSignal.timeout(15_000),
            });
            if (!response.ok) {
                throw new Error(`events endpoint answered ${response.status}`);
            }

            return ((await response.json()) as { acks?: Acks }).acks ?? {};
        },
        { onDeliveryError: (error) => console.error(`[argos-daemon] event delivery failed: ${error instanceof Error ? error.message : String(error)}`) },
    );

    const queryFactory: QueryFactory = (params) => query(params) as unknown as QueryLike;

    const { app, sessions } = buildApp({
        daemonToken,
        outbox,
        queryFactory,
        environment: {
            workspaceDir: process.env.ARGOS_WORKSPACE_DIR ?? '/workspace',
            agentsDir: process.env.ARGOS_AGENTS_DIR ?? '/home/agent/.claude-agents',
            claudeBin,
        },
        credentialHelper: process.env.ARGOS_CREDENTIAL_HELPER ?? '/opt/argos/bin/git-credential-argos',
        oneshotRunner: (params) => query(params) as unknown as AsyncIterable<SdkMessageLike>,
        versions: { sdk: sdkVersion(), claude: claudeVersion(claudeBin) },
    });

    const credentials = new RepoCredentialCache(managerCredentialFetcher(credentialUrl, daemonToken));
    const credentialServer = await startCredentialServer(credentials, credentialPort);

    await app.listen({ host: '0.0.0.0', port });
    console.log(`[argos-daemon] listening on :${port}`);

    let shuttingDown = false;
    const shutdown = async (): Promise<void> => {
        if (shuttingDown) {
            return;
        }
        shuttingDown = true;
        await Promise.allSettled([...sessions.values()].map((session) => session.stop()));
        await outbox.flush().catch(() => {});
        outbox.stop();
        credentialServer.close();
        await app.close();
        process.exit(0);
    };
    process.on('SIGTERM', () => void shutdown());
    process.on('SIGINT', () => void shutdown());
}

main().catch((error: unknown) => {
    console.error(`[argos-daemon] fatal: ${error instanceof Error ? error.message : String(error)}`);
    process.exit(1);
});
