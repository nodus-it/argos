import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { EventBatch } from '../src/events.js';
import { buildApp } from '../src/http.js';
import { Outbox } from '../src/outbox.js';
import type { SetupScript } from '../src/worktrees.js';
import { assistantText, fakeFactory, textOfUserMessage, waitFor, type Script } from './fakeQuery.js';
import { git, workspaceFixture, type WorkspaceFixture } from './workspaceFixture.js';

const TOKEN = 'daemon-token-123';
const auth = { authorization: `Bearer ${TOKEN}` };
const startBody = { worktree: 'fix-login', agent_id: 'agent_1', oauth_token: 'oat', model: 'claude-sonnet-5-5', permission_mode: 'default', seq_offset: 10 };
const prepareBody = (taskId: string, branch: string) => ({ task_id: taskId, branch, base_branch: 'main', env: { DB_DATABASE: `db_${taskId}` } });
const message = (id: string, text: string) => ({ client_msg_id: id, author: { id: 3, name: 'Ada' }, text });

describe('daemon HTTP API', () => {
    let ws: WorkspaceFixture;
    let batches: EventBatch[];
    let setupCalls: { dir: string; env: Record<string, string> }[];
    let outboxes: Outbox[];

    beforeEach(async () => {
        ws = await workspaceFixture();
        batches = [];
        setupCalls = [];
        outboxes = [];
    });
    afterEach(() => {
        // A failed test must not leave an outbox re-sending into the next one.
        outboxes.forEach((outbox) => outbox.stop());
        ws.cleanup();
    });

    const setupScript: SetupScript = async (dir, env) => {
        setupCalls.push({ dir, env });

        return { code: 0, output: 'composer install\nsetup done\n' };
    };

    const make = (script: Script) => {
        const outbox = new Outbox(async (batch) => {
            batches.push(batch);

            return {};
        }, { flushIntervalMs: 1 });
        outboxes.push(outbox);
        const { factory, queries } = fakeFactory(script);
        const built = buildApp({
            daemonToken: TOKEN,
            outbox,
            queryFactory: factory,
            environment: { workspaceDir: ws.layout.root, agentsDir: join(ws.tmp, 'agents') },
            credentialHelper: '/opt/argos/bin/git-credential-argos',
            setupScript,
        });

        return { ...built, outbox, queries };
    };

    const events = () => batches.flatMap((b) => b.events);
    /** Distinct events (unacked ones are re-sent). */
    const distinct = (type: string, taskId?: string) => [
        ...new Map(events().filter((e) => e.type === type && (taskId === undefined || e.task_id === taskId)).map((e) => [`${e.task_id}:${e.seq}`, e])).values(),
    ];

    /** Prepare a worktree over HTTP and wait for the outcome. */
    const prepare = async (app: ReturnType<typeof make>['app'], slug: string, taskId: string, branch = `argos/${slug}`) => {
        const before = distinct('prepare.completed', taskId).length + distinct('prepare.failed', taskId).length;
        const response = await app.inject({ method: 'POST', url: `/worktrees/${slug}/prepare`, headers: auth, payload: prepareBody(taskId, branch) });
        expect(response.statusCode).toBe(202);
        await waitFor(() => distinct('prepare.completed', taskId).length + distinct('prepare.failed', taskId).length > before, 10_000);

        return [...distinct('prepare.completed', taskId), ...distinct('prepare.failed', taskId)].sort((a, b) => a.seq - b.seq).at(-1)!;
    };

    it('rejects requests without or with a wrong bearer token', async () => {
        const { app, outbox } = make(async function* () {});
        expect((await app.inject({ method: 'GET', url: '/health' })).statusCode).toBe(401);
        expect((await app.inject({ method: 'GET', url: '/health', headers: { authorization: 'Bearer nope' } })).statusCode).toBe(401);
        const ok = await app.inject({ method: 'GET', url: '/health', headers: auth });
        expect(ok.statusCode).toBe(200);
        expect(ok.json()).toMatchObject({ version: '0.1.0', tasks: [] });
        outbox.stop();
    });

    it('validates bodies', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: { ...startBody, permission_mode: 'yolo' } });
        expect(response.statusCode).toBe(422);
        expect(response.json().error.code).toBe('validation_failed');
        expect((await app.inject({ method: 'POST', url: '/tasks/bad id/start', headers: auth, payload: startBody })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: { ...startBody, worktree: undefined } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: { ...startBody, worktree: '../repo' } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/worktrees/x/prepare', headers: auth, payload: { ...prepareBody('t1', '--force'), branch: '--force' } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/worktrees/x/prepare', headers: auth, payload: { ...prepareBody('t1', 'a/b'), env: { 'bad-key': 'x' } } })).statusCode).toBe(422);
        outbox.stop();
    });

    it('refuses to start a session without a prepared worktree', async () => {
        const { app, outbox, queries } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: startBody });
        expect(response.statusCode).toBe(409);
        expect(response.json().error.code).toBe('worktree_missing');
        expect(queries).toHaveLength(0);
        outbox.stop();
    });

    it('404s worktree routes until the worktree is prepared', async () => {
        const { app, outbox } = make(async function* () {});
        const requests = [
            { method: 'GET' as const, url: '/worktrees/fix-login/files?q=a' },
            { method: 'GET' as const, url: '/worktrees/fix-login/files/content?path=README.md' },
            { method: 'GET' as const, url: '/worktrees/fix-login/git/status' },
            { method: 'GET' as const, url: '/worktrees/fix-login/git/diff?base=origin/main' },
            { method: 'GET' as const, url: '/worktrees/fix-login/git/changes?base=origin/main' },
            { method: 'POST' as const, url: '/worktrees/fix-login/git/commit', payload: { subject: 'x', author: { name: 'A', email: 'a@b' } } },
            { method: 'POST' as const, url: '/worktrees/fix-login/git/push', payload: { branch: 'argos/fix-login' } },
            { method: 'POST' as const, url: '/worktrees/fix-login/checkpoints', payload: { task_id: 't1' } },
            { method: 'GET' as const, url: '/worktrees/fix-login/checkpoints/diff?ref=refs/argos/checkpoints/t1/1' },
            { method: 'POST' as const, url: '/worktrees/fix-login/checkpoints/restore', payload: { ref: 'refs/argos/checkpoints/t1/1' } },
        ];
        for (const request of requests) {
            const response = await app.inject({ ...request, headers: auth });
            expect(response.statusCode, request.url).toBe(404);
            expect(response.json().error.code).toBe('unknown_worktree');
        }
        expect((await app.inject({ method: 'GET', url: '/worktrees/Bad_Slug/git/status', headers: auth })).statusCode).toBe(422);
        outbox.stop();
    });

    it('prepares a worktree, writes the task env and runs the setup script only on creation', async () => {
        const { app, outbox } = make(async function* () {});

        const completed = await prepare(app, 'fix-login', 't1');
        const path = join(ws.layout.tasksDir, 'fix-login');
        expect(completed.type).toBe('prepare.completed');
        expect(completed.data).toEqual({ path, base_commit: ws.mainCommit, created: true, setup: 'ok', log: 'composer install\nsetup done\n' });
        expect(distinct('prepare.step', 't1').map((e) => e.data.step)).toEqual(['worktree', 'setup']);
        expect(setupCalls).toEqual([{ dir: path, env: { DB_DATABASE: 'db_t1' } }]);
        expect(readFileSync(join(path, '.env'), 'utf8')).toContain('DB_DATABASE=db_t1\n');
        expect(JSON.parse(readFileSync(join(path, '.agent', 'task-env.json'), 'utf8'))).toEqual({ DB_DATABASE: 'db_t1' });
        // The task's own files are ignored through the main clone's exclude.
        expect(git(['check-ignore', '.agent/task-env.json'], path).trim()).toBe('.agent/task-env.json');
        expect(git(['symbolic-ref', '--short', 'HEAD'], path).trim()).toBe('argos/fix-login');

        const again = await prepare(app, 'fix-login', 't1');
        expect(again.data).toMatchObject({ path, base_commit: ws.mainCommit, created: false, setup: 'skipped', log: '' });
        expect(setupCalls).toHaveLength(1);

        const status = await app.inject({ method: 'GET', url: '/worktrees/fix-login/git/status', headers: auth });
        expect(status.statusCode).toBe(200);
        expect(status.json()).toMatchObject({ branch: 'argos/fix-login' });
        outbox.stop();
    }, 20_000);

    it('passes the setup branch to the setup script', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/worktrees/fix-login/prepare', headers: auth, payload: { ...prepareBody('t1', 'argos/fix-login'), setup_ref: 'argos/setup' } });
        expect(response.statusCode).toBe(202);
        await waitFor(() => distinct('prepare.completed', 't1').length === 1, 10_000);
        expect(setupCalls[0]!.env).toEqual({ DB_DATABASE: 'db_t1', ARGOS_SETUP_REF: 'argos/setup' });
        // The setup branch is not written into the task's .env.
        expect(readFileSync(join(ws.layout.tasksDir, 'fix-login', '.env'), 'utf8')).not.toContain('ARGOS_SETUP_REF');
        outbox.stop();
    }, 20_000);

    it('checks the setup steps of a branch in a throwaway worktree', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/setup/check', headers: auth, payload: { ref: 'main', env: { DB_DATABASE: 'db_check' } } });
        expect(response.statusCode).toBe(200);
        expect(response.json()).toEqual({ setup: 'ok', log: 'composer install\nsetup done\n' });
        expect(setupCalls).toEqual([{ dir: join(ws.layout.tasksDir, 'argos-setup-check'), env: { DB_DATABASE: 'db_check' } }]);
        expect(existsSync(join(ws.layout.tasksDir, 'argos-setup-check'))).toBe(false);

        const missing = await app.inject({ method: 'POST', url: '/setup/check', headers: auth, payload: { ref: 'nope' } });
        expect(missing.statusCode).toBe(422);
        expect(missing.json().error.code).toBe('branch_not_found');
        outbox.stop();
    }, 20_000);

    it('reports a failed prepare', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/worktrees/fix-login/prepare', headers: auth, payload: { ...prepareBody('t1', 'argos/x'), base_branch: 'nope' } });
        expect(response.statusCode).toBe(202);
        await waitFor(() => distinct('prepare.failed', 't1').length === 1, 10_000);
        expect(distinct('prepare.failed', 't1')[0]!.data.code).toBe('branch_not_found');
        expect(setupCalls).toHaveLength(0);
        outbox.stop();
    }, 20_000);

    it('runs a task end to end in its worktree: start, message, events with offset seq, stop', async () => {
        const { app, outbox, queries } = make(async function* (message) {
            yield assistantText('m1', `echo ${textOfUserMessage(message)}`);
        });
        await prepare(app, 'fix-login', 't1');

        const start = await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: startBody });
        expect(start.statusCode).toBe(200);
        // Idempotent while running.
        await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: startBody });
        expect(queries).toHaveLength(1);
        expect(queries[0]!.options.cwd).toBe(join(ws.layout.tasksDir, 'fix-login'));
        expect(queries[0]!.options.env?.DB_DATABASE).toBe('db_t1');
        expect(queries[0]!.options.hooks?.PreToolUse).toHaveLength(1);

        const send = await app.inject({ method: 'POST', url: '/tasks/t1/messages', headers: auth, payload: message('e1', 'hi') });
        expect(send.statusCode).toBe(202);
        expect(send.json()).toEqual({ queued: true });

        await waitFor(() => events().some((e) => e.type === 'turn.completed'));
        // Prepare events (seq 1–3) are re-sent while unacked; the session continues at the offset.
        const sessionEvents = events().filter((e) => !e.type.startsWith('prepare.'));
        expect(Math.min(...sessionEvents.map((e) => e.seq))).toBe(11);
        expect(events().every((e) => e.task_id === 't1')).toBe(true);
        expect(events().find((e) => e.type === 'assistant.message')!.data.text).toBe('echo [Ada]: hi');

        const health = (await app.inject({ method: 'GET', url: '/health', headers: auth })).json();
        expect(health.tasks).toEqual([{ id: 't1', state: 'idle', claude_session_id: 'session-1' }]);

        // The worktree cannot go while its session is open.
        const removeBusy = await app.inject({ method: 'DELETE', url: '/worktrees/fix-login', headers: auth });
        expect(removeBusy.statusCode).toBe(409);

        expect((await app.inject({ method: 'POST', url: '/tasks/t1/stop', headers: auth })).statusCode).toBe(202);
        expect((await app.inject({ method: 'POST', url: '/tasks/t1/interrupt', headers: auth })).statusCode).toBe(404);

        expect((await app.inject({ method: 'DELETE', url: '/worktrees/fix-login', headers: auth })).statusCode).toBe(202);
        expect((await app.inject({ method: 'GET', url: '/worktrees/fix-login/git/status', headers: auth })).statusCode).toBe(404);
        outbox.stop();
    }, 20_000);

    it('resolves approvals and 404s unknown ones', async () => {
        const { app, outbox } = make(async function* (_message, ctx) {
            const decision = await ctx.askPermission('Write', { file_path: 'x' });
            yield assistantText('m', decision.behavior);
        });
        await prepare(app, 'fix-login', 't1');
        await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: startBody });
        await app.inject({ method: 'POST', url: '/tasks/t1/messages', headers: auth, payload: message('e1', 'go') });
        await waitFor(() => events().some((e) => e.type === 'approval.requested'));
        const approvalId = events().find((e) => e.type === 'approval.requested')!.data.approval_id as string;

        expect((await app.inject({ method: 'POST', url: '/tasks/t1/approvals/unknown', headers: auth, payload: { decision: 'allow' } })).statusCode).toBe(404);
        expect((await app.inject({ method: 'POST', url: `/tasks/t1/approvals/${approvalId}`, headers: auth, payload: { decision: 'deny', message: 'no' } })).statusCode).toBe(202);
        await waitFor(() => events().some((e) => e.type === 'assistant.message'));
        expect(events().find((e) => e.type === 'assistant.message')!.data.text).toBe('deny');
        await app.inject({ method: 'POST', url: '/tasks/t1/stop', headers: auth });
        outbox.stop();
    }, 20_000);

    it('forwards permission mode and model', async () => {
        const { app, outbox, queries } = make(async function* () {});
        await prepare(app, 'fix-login', 't1');
        await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: startBody });
        expect((await app.inject({ method: 'POST', url: '/tasks/t1/permission-mode', headers: auth, payload: { mode: 'plan' } })).statusCode).toBe(202);
        expect((await app.inject({ method: 'POST', url: '/tasks/t1/model', headers: auth, payload: { model: 'claude-opus-5-5' } })).statusCode).toBe(202);
        expect(queries[0]!.permissionModes).toEqual(['plan']);
        expect(queries[0]!.models).toEqual(['claude-opus-5-5']);
        await app.inject({ method: 'POST', url: '/tasks/t1/stop', headers: auth });
        outbox.stop();
    }, 20_000);

    it('404s messages for tasks that were never started', async () => {
        const { app, outbox } = make(async function* () {});
        const response = await app.inject({ method: 'POST', url: '/tasks/t9/messages', headers: auth, payload: message('e', 'x') });
        expect(response.statusCode).toBe(404);
        expect(response.json().error.code).toBe('unknown_task');
        outbox.stop();
    });

    it('serves git in the worktree and refuses unsafe refs, empty commits and missing oneshot runners', async () => {
        const { app, outbox } = make(async function* () {});
        await prepare(app, 'fix-login', 't1');

        expect((await app.inject({ method: 'GET', url: '/worktrees/fix-login/git/diff?base=--output=/tmp/x', headers: auth })).statusCode).toBe(422);
        expect((await app.inject({ method: 'GET', url: '/worktrees/fix-login/git/diff?base=../etc', headers: auth })).statusCode).toBe(422);

        // .env is new in the worktree; commit it, then nothing is left.
        const commit = await app.inject({ method: 'POST', url: '/worktrees/fix-login/git/commit', headers: auth, payload: { subject: 'feat: x', author: { name: 'A', email: 'a@b' } } });
        expect(commit.statusCode).toBe(201);
        expect(git(['log', '-1', '--format=%s', 'argos/fix-login'], ws.layout.mainDir).trim()).toBe('feat: x');
        const empty = await app.inject({ method: 'POST', url: '/worktrees/fix-login/git/commit', headers: auth, payload: { subject: 'feat: y', author: { name: 'A', email: 'a@b' } } });
        expect(empty.statusCode).toBe(409);
        expect(empty.json().error.code).toBe('no_changes');

        const changes = await app.inject({ method: 'GET', url: `/worktrees/fix-login/git/changes?base=${ws.mainCommit}`, headers: auth });
        expect(changes.json().files.map((f: { path: string }) => f.path)).toEqual(['.env']);

        expect((await app.inject({ method: 'POST', url: '/git/fetch', headers: auth })).statusCode).toBe(202);

        const oneshot = await app.inject({ method: 'POST', url: '/oneshot', headers: auth, payload: { agent_id: 'a', oauth_token: 't', model: 'm', system_prompt: 's', user_prompt: 'u' } });
        expect(oneshot.statusCode).toBe(501);
        outbox.stop();
    }, 20_000);

    it('checkpoints every turn and restores only while no session works in that worktree', async () => {
        const worktree = join(ws.layout.tasksDir, 'fix-login');
        let release!: () => void;
        const working = new Promise<void>((resolve) => (release = resolve));
        const { app, outbox } = make(async function* (message) {
            writeFileSync(join(worktree, 'README.md'), 'v2\n');
            await working;
            yield assistantText('m1', `done ${textOfUserMessage(message)}`);
        });
        await prepare(app, 'fix-login', 't1');
        await prepare(app, 'other-task', 't2');
        await app.inject({ method: 'POST', url: '/tasks/t1/start', headers: auth, payload: startBody });
        await app.inject({ method: 'POST', url: '/tasks/t1/messages', headers: auth, payload: message('42', 'change it') });

        await waitFor(() => distinct('checkpoint.created', 't1').length === 1);
        const created = distinct('checkpoint.created', 't1')[0]!;
        expect(created.data).toMatchObject({ ref: 'refs/argos/checkpoints/t1/1', n: 1, client_msg_id: '42' });

        const busy = await app.inject({ method: 'POST', url: '/worktrees/fix-login/checkpoints/restore', headers: auth, payload: { ref: created.data.ref } });
        expect(busy.statusCode).toBe(409);
        expect(busy.json().error.code).toBe('task_running');

        // Another task's worktree is not affected by t1's busy session.
        writeFileSync(join(ws.layout.tasksDir, 'other-task', 'README.md'), 'other\n');
        const other = await app.inject({ method: 'POST', url: '/worktrees/other-task/checkpoints', headers: auth, payload: { task_id: 't2' } });
        expect(other.statusCode).toBe(201);
        writeFileSync(join(ws.layout.tasksDir, 'other-task', 'README.md'), 'other changed\n');
        const otherRestore = await app.inject({ method: 'POST', url: '/worktrees/other-task/checkpoints/restore', headers: auth, payload: { ref: other.json().ref } });
        expect(otherRestore.statusCode).toBe(200);
        expect(readFileSync(join(ws.layout.tasksDir, 'other-task', 'README.md'), 'utf8')).toBe('other\n');

        release();
        await waitFor(() => events().some((e) => e.type === 'turn.completed'));
        const preview = await app.inject({ method: 'GET', url: `/worktrees/fix-login/checkpoints/diff?ref=${encodeURIComponent(String(created.data.ref))}`, headers: auth });
        expect(preview.json().diff).toContain('+v2');

        const restored = await app.inject({ method: 'POST', url: '/worktrees/fix-login/checkpoints/restore', headers: auth, payload: { ref: created.data.ref } });
        expect(restored.statusCode).toBe(200);
        expect(restored.json().before.n).toBe(2);
        expect(readFileSync(join(worktree, 'README.md'), 'utf8')).toBe('hello\n');
        await waitFor(() => distinct('checkpoint.created', 't1').length === 2);

        const manual = await app.inject({ method: 'POST', url: '/worktrees/fix-login/checkpoints', headers: auth, payload: { task_id: 't1', label: 'before refactor' } });
        expect(manual.statusCode).toBe(201);
        expect(manual.json()).toMatchObject({ n: 3, label: 'before refactor' });

        expect((await app.inject({ method: 'POST', url: '/worktrees/fix-login/checkpoints/restore', headers: auth, payload: { ref: 'refs/heads/main' } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/worktrees/fix-login/checkpoints/restore', headers: auth, payload: { ref: 'refs/argos/checkpoints/t1/99' } })).statusCode).toBe(404);
        const unknownDiff = await app.inject({ method: 'GET', url: `/worktrees/fix-login/checkpoints/diff?ref=${encodeURIComponent('refs/argos/checkpoints/t1/99')}`, headers: auth });
        expect(unknownDiff.statusCode).toBe(404);
        expect(unknownDiff.json().error.code).toBe('unknown_checkpoint');
        await app.inject({ method: 'POST', url: '/tasks/t1/stop', headers: auth });
        outbox.stop();
    }, 20_000);
});
