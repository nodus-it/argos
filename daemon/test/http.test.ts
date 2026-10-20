import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { EventBatch } from '../src/events.js';
import { buildApp } from '../src/http.js';
import { Outbox } from '../src/outbox.js';
import { assistantText, fakeFactory, textOfUserMessage, waitFor, type Script } from './fakeQuery.js';

const TOKEN = 'daemon-token-123';
const auth = { authorization: `Bearer ${TOKEN}` };
const startBody = { agent_id: 'agent_1', oauth_token: 'oat', model: 'claude-sonnet-5-5', permission_mode: 'default', seq_offset: 10 };

describe('daemon HTTP API', () => {
    let dir: string;
    let batches: EventBatch[];

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-http-'));
        batches = [];
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const make = (script: Script) => {
        const outbox = new Outbox(async (batch) => {
            batches.push(batch);

            return {};
        }, { flushIntervalMs: 1 });
        const { factory, queries } = fakeFactory(script);
        const built = buildApp({
            daemonToken: TOKEN,
            outbox,
            queryFactory: factory,
            environment: { workspaceDir: dir, agentsDir: join(dir, 'agents') },
            credentialHelper: '/opt/argos/bin/git-credential-argos',
        });

        return { ...built, outbox, queries };
    };

    const events = () => batches.flatMap((b) => b.events);

    it('rejects requests without or with a wrong bearer token', async () => {
        const { app, outbox } = make(async function* () {});
        expect((await app.inject({ method: 'GET', url: '/health' })).statusCode).toBe(401);
        expect((await app.inject({ method: 'GET', url: '/health', headers: { authorization: 'Bearer nope' } })).statusCode).toBe(401);
        const ok = await app.inject({ method: 'GET', url: '/health', headers: auth });
        expect(ok.statusCode).toBe(200);
        expect(ok.json()).toMatchObject({ version: '0.1.0', threads: [] });
        outbox.stop();
    });

    it('validates bodies', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/threads/t1/start', headers: auth, payload: { ...startBody, permission_mode: 'yolo' } });
        expect(response.statusCode).toBe(422);
        expect(response.json().error.code).toBe('validation_failed');
        expect((await app.inject({ method: 'POST', url: '/threads/bad id/start', headers: auth, payload: startBody })).statusCode).toBe(422);
        outbox.stop();
    });

    it('runs a thread end to end: start, message, events with offset seq, stop', async () => {
        const { app, outbox, queries } = make(async function* (message) {
            yield assistantText('m1', `echo ${textOfUserMessage(message)}`);
        });

        const start = await app.inject({ method: 'POST', url: '/threads/t1/start', headers: auth, payload: startBody });
        expect(start.statusCode).toBe(200);
        // Idempotent while running.
        await app.inject({ method: 'POST', url: '/threads/t1/start', headers: auth, payload: startBody });
        expect(queries).toHaveLength(1);

        const send = await app.inject({ method: 'POST', url: '/threads/t1/messages', headers: auth, payload: { client_msg_id: 'e1', author: { id: 3, name: 'Ada' }, text: 'hi' } });
        expect(send.statusCode).toBe(202);
        expect(send.json()).toEqual({ queued: true });

        await waitFor(() => events().some((e) => e.type === 'turn.completed'));
        expect(events()[0]!.seq).toBe(11);
        expect(events().every((e) => e.thread_id === 't1')).toBe(true);
        expect(events().find((e) => e.type === 'assistant.message')!.data.text).toBe('echo [Ada]: hi');

        const health = (await app.inject({ method: 'GET', url: '/health', headers: auth })).json();
        expect(health.threads).toEqual([{ id: 't1', state: 'idle', claude_session_id: 'session-1' }]);

        expect((await app.inject({ method: 'POST', url: '/threads/t1/stop', headers: auth })).statusCode).toBe(202);
        expect((await app.inject({ method: 'POST', url: '/threads/t1/interrupt', headers: auth })).statusCode).toBe(404);
        outbox.stop();
    });

    it('resolves approvals and 404s unknown ones', async () => {
        const { app, outbox } = make(async function* (_message, ctx) {
            const decision = await ctx.askPermission('Write', { file_path: 'x' });
            yield assistantText('m', decision.behavior);
        });
        await app.inject({ method: 'POST', url: '/threads/t1/start', headers: auth, payload: startBody });
        await app.inject({ method: 'POST', url: '/threads/t1/messages', headers: auth, payload: { client_msg_id: 'e1', author: { id: 1, name: 'A' }, text: 'go' } });
        await waitFor(() => events().some((e) => e.type === 'approval.requested'));
        const approvalId = events().find((e) => e.type === 'approval.requested')!.data.approval_id as string;

        expect((await app.inject({ method: 'POST', url: '/threads/t1/approvals/unknown', headers: auth, payload: { decision: 'allow' } })).statusCode).toBe(404);
        expect((await app.inject({ method: 'POST', url: `/threads/t1/approvals/${approvalId}`, headers: auth, payload: { decision: 'deny', message: 'no' } })).statusCode).toBe(202);
        await waitFor(() => events().some((e) => e.type === 'assistant.message'));
        expect(events().find((e) => e.type === 'assistant.message')!.data.text).toBe('deny');
        await app.inject({ method: 'POST', url: '/threads/t1/stop', headers: auth });
        outbox.stop();
    });

    it('forwards permission mode and model', async () => {
        const { app, outbox, queries } = make(async function* () {});
        await app.inject({ method: 'POST', url: '/threads/t1/start', headers: auth, payload: startBody });
        expect((await app.inject({ method: 'POST', url: '/threads/t1/permission-mode', headers: auth, payload: { mode: 'plan' } })).statusCode).toBe(202);
        expect((await app.inject({ method: 'POST', url: '/threads/t1/model', headers: auth, payload: { model: 'claude-opus-5-5' } })).statusCode).toBe(202);
        expect(queries[0]!.permissionModes).toEqual(['plan']);
        expect(queries[0]!.models).toEqual(['claude-opus-5-5']);
        await app.inject({ method: 'POST', url: '/threads/t1/stop', headers: auth });
        outbox.stop();
    });

    it('404s messages for threads that were never started', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/threads/t9/messages', headers: auth, payload: { client_msg_id: 'e', author: { id: 1, name: 'A' }, text: 'x' } });
        expect(response.statusCode).toBe(404);
        expect(response.json().error.code).toBe('unknown_thread');
        outbox.stop();
    });

    it('serves git status and refuses unsafe refs, empty commits and missing oneshot runners', async () => {
        const { execFileSync } = await import('node:child_process');
        execFileSync('git', ['init', '-q', '-b', 'main', dir], { env: { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null' } });
        const { app, outbox } = make(async function* () {});

        const statusResponse = await app.inject({ method: 'GET', url: '/git/status', headers: auth });
        expect(statusResponse.statusCode).toBe(200);
        expect(statusResponse.json()).toMatchObject({ branch: 'main', dirty: false });

        expect((await app.inject({ method: 'GET', url: '/git/diff?base=--output=/tmp/x', headers: auth })).statusCode).toBe(422);
        expect((await app.inject({ method: 'GET', url: '/git/diff?base=../etc', headers: auth })).statusCode).toBe(422);

        const empty = await app.inject({ method: 'POST', url: '/git/commit', headers: auth, payload: { subject: 'feat: x', author: { name: 'A', email: 'a@b' } } });
        expect(empty.statusCode).toBe(409);
        expect(empty.json().error.code).toBe('no_changes');

        const oneshot = await app.inject({ method: 'POST', url: '/oneshot', headers: auth, payload: { agent_id: 'a', oauth_token: 't', model: 'm', system_prompt: 's', user_prompt: 'u' } });
        expect(oneshot.statusCode).toBe(501);
        outbox.stop();
    });
});
