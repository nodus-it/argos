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

        // No `origin` remote: git fails and the daemon answers 502 with a classified code.
        const fetchFailed = await app.inject({ method: 'POST', url: '/git/fetch', headers: auth });
        expect(fetchFailed.statusCode).toBe(502);
        expect(fetchFailed.json().error).toMatchObject({ code: 'unknown', message: 'git fetch failed' });

        const oneshot = await app.inject({ method: 'POST', url: '/oneshot', headers: auth, payload: { agent_id: 'a', oauth_token: 't', model: 'm', system_prompt: 's', user_prompt: 'u' } });
        expect(oneshot.statusCode).toBe(501);
        outbox.stop();
    });

    it('checkpoints every turn and restores only while Claude is not working', async () => {
        const { execFileSync } = await import('node:child_process');
        const { writeFileSync, readFileSync } = await import('node:fs');
        const gitEnv = { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null' };
        execFileSync('git', ['init', '-q', '-b', 'main', dir], { env: gitEnv });
        writeFileSync(join(dir, 'README.md'), 'v1\n');
        execFileSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', 'add', '.'], { cwd: dir, env: gitEnv });
        execFileSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init'], { cwd: dir, env: gitEnv });

        let release!: () => void;
        const working = new Promise<void>((resolve) => (release = resolve));
        const { app, outbox } = make(async function* (message) {
            writeFileSync(join(dir, 'README.md'), 'v2\n');
            await working;
            yield assistantText('m1', `done ${textOfUserMessage(message)}`);
        });
        await app.inject({ method: 'POST', url: '/threads/t1/start', headers: auth, payload: startBody });
        await app.inject({ method: 'POST', url: '/threads/t1/messages', headers: auth, payload: { client_msg_id: '42', author: { id: 3, name: 'Ada' }, text: 'change it' } });

        await waitFor(() => events().some((e) => e.type === 'checkpoint.created'));
        const created = events().find((e) => e.type === 'checkpoint.created')!;
        expect(created.data).toMatchObject({ ref: 'refs/argos/checkpoints/t1/1', n: 1, client_msg_id: '42' });

        const busy = await app.inject({ method: 'POST', url: '/checkpoints/restore', headers: auth, payload: { ref: created.data.ref } });
        expect(busy.statusCode).toBe(409);
        expect(busy.json().error.code).toBe('thread_running');

        // Another thread's checkpoint: the restore would still rewrite t1's working tree.
        const other = await app.inject({ method: 'POST', url: '/checkpoints', headers: auth, payload: { thread_id: 't2' } });
        const otherBusy = await app.inject({ method: 'POST', url: '/checkpoints/restore', headers: auth, payload: { ref: other.json().ref } });
        expect(otherBusy.statusCode).toBe(409);
        expect(otherBusy.json().error.code).toBe('thread_running');

        release();
        await waitFor(() => events().some((e) => e.type === 'turn.completed'));
        const preview = await app.inject({ method: 'GET', url: `/checkpoints/diff?ref=${encodeURIComponent(String(created.data.ref))}`, headers: auth });
        expect(preview.json().diff).toContain('+v2');

        const restored = await app.inject({ method: 'POST', url: '/checkpoints/restore', headers: auth, payload: { ref: created.data.ref } });
        expect(restored.statusCode).toBe(200);
        expect(restored.json().before.n).toBe(2);
        expect(readFileSync(join(dir, 'README.md'), 'utf8')).toBe('v1\n');
        // Unacked events are re-sent; count distinct ones.
        await waitFor(() => new Set(events().filter((e) => e.type === 'checkpoint.created').map((e) => e.seq)).size === 2);

        const manual = await app.inject({ method: 'POST', url: '/checkpoints', headers: auth, payload: { thread_id: 't1', label: 'before refactor' } });
        expect(manual.statusCode).toBe(201);
        expect(manual.json()).toMatchObject({ n: 3, label: 'before refactor' });

        expect((await app.inject({ method: 'POST', url: '/checkpoints/restore', headers: auth, payload: { ref: 'refs/heads/main' } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/checkpoints/restore', headers: auth, payload: { ref: 'refs/argos/checkpoints/t1/99' } })).statusCode).toBe(404);
        const unknownDiff = await app.inject({ method: 'GET', url: `/checkpoints/diff?ref=${encodeURIComponent('refs/argos/checkpoints/t1/99')}`, headers: auth });
        expect(unknownDiff.statusCode).toBe(404);
        expect(unknownDiff.json().error.code).toBe('unknown_checkpoint');
        await app.inject({ method: 'POST', url: '/threads/t1/stop', headers: auth });
        outbox.stop();
    });
});
