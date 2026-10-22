import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { EventBatch, PendingEvent } from '../src/events.js';
import { GateRunner, GateScriptError, GatesBusyError, parseGates, type GateScript } from '../src/gates.js';
import { buildApp } from '../src/http.js';
import { layoutFor, type Layout } from '../src/layout.js';
import { Outbox } from '../src/outbox.js';
import { prepareWorktree } from '../src/worktrees.js';
import { fakeFactory, waitFor } from './fakeQuery.js';
import { commitAll, git, workspaceFixture, type WorkspaceFixture } from './workspaceFixture.js';

const TOKEN = 'daemon-token-123';
const auth = { authorization: `Bearer ${TOKEN}` };

type ScriptResult = { code: number; stdout: string; stderr: string };

/**
 * A fake run-gates.sh: pest fails, pint passes, the rest skips. Calls are
 * `--dir <dir> <command> …`; `--baseline` writes baseline files naming the
 * checked-out commit.
 */
function fakeScript(calls: string[][], overrides: Partial<Record<string, (args: string[]) => Promise<ScriptResult>>> = {}, envs: (Record<string, string> | undefined)[] = []): GateScript {
    return async (args, _timeoutMs, env) => {
        calls.push(args);
        envs.push(env);
        expect(args[0]).toBe('--dir');
        const dir = args[1]!;
        const command = args[2] ?? '';
        const override = overrides[command];
        if (override !== undefined) {
            return override(args);
        }
        switch (command) {
            case '--run':
                return { code: 0, stdout: JSON.stringify({ artisan: 'skip', pint: 'pass', pest: 'fail' }), stderr: '[INFO] noise' };
            case '--log-path':
                return { code: 0, stdout: join(dir, '.agent', 'logs', `${args[3]}.${args[4]}.log`), stderr: '' };
            case '--fix-prompt':
                return { code: 0, stdout: `# Quality-Gate-Fix: ${args[3]}\n`, stderr: '' };
            case '--baseline': {
                const head = git(['rev-parse', 'HEAD'], dir).trim();
                mkdirSync(join(dir, '.agent'), { recursive: true });
                writeFileSync(join(dir, '.agent', 'pest-baseline.xml'), `<testsuites commit="${head}"/>`);
                writeFileSync(join(dir, '.agent', 'phpstan-baseline.json'), `{"commit":"${head}"}`);

                return { code: 0, stdout: '', stderr: '' };
            }
            default:
                return { code: 0, stdout: '', stderr: '' };
        }
    };
}

describe('quality gate runs', () => {
    let root: string;
    let dir: string;
    let layout: Layout;
    let calls: string[][];
    let emitted: PendingEvent[];

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'argos-gates-'));
        layout = layoutFor(root);
        dir = join(layout.tasksDir, 'fix-login');
        mkdirSync(join(dir, '.agent', 'logs'), { recursive: true });
        calls = [];
        emitted = [];
    });
    afterEach(() => rmSync(root, { recursive: true, force: true }));

    const runner = (script: GateScript) => new GateRunner(script, (event) => emitted.push(event), layout);

    it('runs the gates in the worktree and reports results with log tails', async () => {
        writeFileSync(join(dir, '.agent', 'logs', 'pest.r1.log'), 'x'.repeat(10_000) + '\nFAILED LoginTest\n');
        writeFileSync(join(dir, '.agent', 'logs', 'pint.r1.log'), 'PASS\n');

        await runner(fakeScript(calls)).start('r1', dir, null);

        expect(calls[0]).toEqual(['--dir', dir, '--run', 'r1']);
        expect(calls.slice(1)).toEqual(expect.arrayContaining([['--dir', dir, '--log-path', 'pest', 'r1']]));
        expect(emitted.map((e) => e.type)).toEqual(['gates.started', 'gates.completed']);
        expect(emitted[0]!.data).toEqual({ run_id: 'r1', baseline: 'missing' });
        const completed = emitted[1]!.data as { gates: Record<string, string>; logs: Record<string, string>; baseline: string };
        expect(completed.gates).toEqual({ artisan: 'skip', pint: 'pass', pest: 'fail' });
        expect(Object.keys(completed.logs).sort()).toEqual(['pest', 'pint']);
        expect(completed.logs.pest).toBe('FAILED LoginTest\n');
        expect(completed.logs.pint).toBe('PASS\n');
        expect(completed.baseline).toBe('missing');
    });

    it('allows one run at a time', async () => {
        let release!: () => void;
        const gate = new Promise<void>((resolve) => (release = resolve));
        const gates = runner(fakeScript(calls, { '--run': async () => { await gate; return { code: 0, stdout: '{}', stderr: '' }; } }));

        const first = gates.start('r1', dir, null);
        expect(() => gates.start('r2', dir, null)).toThrow(GatesBusyError);
        expect(gates.running).toBe('r1');
        release();
        await first;
        expect(gates.running).toBeNull();
    });

    it('reports a failed fix-prompt request as a gate script error', async () => {
        const gates = runner(fakeScript(calls, { '--fix-prompt': async () => ({ code: 2, stdout: '', stderr: 'no such gate' }) }));

        await expect(gates.fixPrompt('pest', 'r1', dir)).rejects.toMatchObject({ status: 502, code: 'gates_failed', message: 'no such gate' });
        expect(calls[0]).toEqual(['--dir', dir, '--fix-prompt', 'pest', 'r1']);
        expect(new GateScriptError('x')).toBeInstanceOf(Error);
    });

    it('serves a gate\'s full log and keeps the end of an oversized one', async () => {
        writeFileSync(join(dir, '.agent', 'logs', 'pest.r1.log'), 'x'.repeat(10_000) + '\nFAILED LoginTest\n');
        const gates = runner(fakeScript(calls));

        const full = await gates.log('pest', 'r1', dir);
        expect(full).toEqual({ log: 'x'.repeat(10_000) + '\nFAILED LoginTest\n', truncated: false, size: 10_018 });
        expect(calls[0]).toEqual(['--dir', dir, '--log-path', 'pest', 'r1']);

        const capped = await gates.log('pest', 'r1', dir, 100);
        expect(capped).toEqual({ log: 'FAILED LoginTest\n', truncated: true, size: 10_018 });

        expect(await gates.log('pint', 'r1', dir)).toEqual({ log: '', truncated: false, size: 0 });
        const broken = runner(fakeScript(calls, { '--log-path': async () => ({ code: 2, stdout: '', stderr: 'invalid gate' }) }));
        await expect(broken.log('pest', 'r1', dir)).rejects.toMatchObject({ status: 502, code: 'gates_failed' });
    });

    it('reports a failed run with the script error', async () => {
        await runner(fakeScript(calls, { '--run': async () => ({ code: 2, stdout: '', stderr: 'run-gates: invalid run id' }) })).start('r1', dir, null);

        expect(emitted[1]).toEqual({ type: 'gates.failed', data: { run_id: 'r1', message: 'run-gates: invalid run id' } });
    });

    it('passes the task env to the run', async () => {
        writeFileSync(join(dir, '.agent', 'task-env.json'), JSON.stringify({ DB_DATABASE: 'task_fix_login' }));
        const envs: (Record<string, string> | undefined)[] = [];

        await runner(fakeScript(calls, {}, envs)).start('r1', dir, null);

        expect(calls[0]![2]).toBe('--run');
        expect(envs[0]).toEqual({ DB_DATABASE: 'task_fix_login' });
    });

    it('clears a stale baseline in the worktree when the base commit has none', async () => {
        writeFileSync(join(dir, '.agent', 'pest-baseline.xml'), '<stale/>');
        const commit = 'a'.repeat(40);

        await runner(fakeScript(calls)).start('r1', dir, commit);

        expect(existsSync(join(dir, '.agent', 'pest-baseline.xml'))).toBe(false);
        expect(emitted[0]!.data).toEqual({ run_id: 'r1', baseline: 'missing' });
    });

    it('only accepts a flat gate → pass/fail/skip map', () => {
        expect(parseGates('{"pest":"fail"}')).toEqual({ pest: 'fail' });
        expect(parseGates('{"pest":"maybe"}')).toBeNull();
        expect(parseGates('[1]')).toBeNull();
        expect(parseGates('not json')).toBeNull();
    });
});

describe('quality gate baselines', () => {
    let ws: WorkspaceFixture;
    let calls: string[][];
    let emitted: PendingEvent[];

    beforeEach(async () => {
        ws = await workspaceFixture();
        calls = [];
        emitted = [];
    });
    afterEach(() => ws.cleanup());

    const runner = (script: GateScript) => new GateRunner(script, (event) => emitted.push(event), ws.layout);

    it('captures a baseline per commit in the main clone and keeps it on disk', async () => {
        // A second base commit the main clone is not on yet.
        writeFileSync(join(ws.seed, 'later.txt'), 'later\n');
        const later = commitAll(ws.seed, 'later');
        git(['push', '-q', 'origin', 'main'], ws.seed);
        git(['fetch', '-q', 'origin'], ws.layout.mainDir);

        const gates = runner(fakeScript(calls));
        expect(gates.baseline(later)).toBe('missing');

        await gates.ensureBaseline(later);

        expect(calls).toEqual([['--dir', ws.layout.mainDir, '--baseline']]);
        expect(git(['rev-parse', 'HEAD'], ws.layout.mainDir).trim()).toBe(later);
        const target = join(ws.layout.baselinesDir, later);
        expect(readFileSync(join(target, 'pest-baseline.xml'), 'utf8')).toBe(`<testsuites commit="${later}"/>`);
        expect(readFileSync(join(target, 'phpstan-baseline.json'), 'utf8')).toBe(`{"commit":"${later}"}`);
        expect(existsSync(join(target, 'captured'))).toBe(true);
        expect(emitted).toEqual([
            { type: 'gates.baseline', data: { commit: later, state: 'running' } },
            { type: 'gates.baseline', data: { commit: later, state: 'ready' } },
        ]);
        expect(gates.baseline(later)).toBe('ready');
        expect(gates.baseline(ws.mainCommit)).toBe('missing');

        // Captured once: a second request and a restarted daemon reuse it.
        await gates.ensureBaseline(later);
        const restarted = runner(fakeScript(calls));
        expect(restarted.baseline(later)).toBe('ready');
        await restarted.ensureBaseline(later);
        expect(calls).toHaveLength(1);
    }, 20_000);

    it('marks a failed capture and writes no marker', async () => {
        const gates = runner(fakeScript(calls, { '--baseline': async () => ({ code: 1, stdout: '', stderr: 'boom' }) }));

        await gates.ensureBaseline(ws.mainCommit);

        expect(gates.baseline(ws.mainCommit)).toBe('failed');
        expect(emitted.at(-1)!.data).toEqual({ commit: ws.mainCommit, state: 'failed' });
        expect(existsSync(join(ws.layout.baselinesDir, ws.mainCommit, 'captured'))).toBe(false);
    });

    it('ignores anything that is not a full commit id', async () => {
        const gates = runner(fakeScript(calls));

        await gates.ensureBaseline('main');
        await gates.ensureBaseline('');

        expect(calls).toHaveLength(0);
        expect(emitted).toHaveLength(0);
    });

    it('copies the base commit\'s baseline into the worktree for a run', async () => {
        const worktree = await prepareWorktree(ws.layout, { slug: 'fix-login', branch: 'argos/fix-login', baseBranch: 'main', env: {} });
        mkdirSync(join(worktree.path, '.agent'), { recursive: true });
        writeFileSync(join(worktree.path, '.agent', 'phpstan-baseline.json'), '{"stale":true}');
        const gates = runner(fakeScript(calls));
        await gates.ensureBaseline(worktree.baseCommit);

        await gates.start('r1', worktree.path, worktree.baseCommit);

        expect(readFileSync(join(worktree.path, '.agent', 'pest-baseline.xml'), 'utf8')).toBe(`<testsuites commit="${ws.mainCommit}"/>`);
        expect(readFileSync(join(worktree.path, '.agent', 'phpstan-baseline.json'), 'utf8')).toBe(`{"commit":"${ws.mainCommit}"}`);
        expect(emitted.find((e) => e.type === 'gates.started')!.data).toEqual({ run_id: 'r1', baseline: 'ready' });
        expect(emitted.find((e) => e.type === 'gates.completed')!.data.baseline).toBe('ready');
        expect(calls).toContainEqual(['--dir', worktree.path, '--run', 'r1']);
    }, 20_000);
});

describe('quality gates over HTTP', () => {
    let ws: WorkspaceFixture;
    let calls: string[][];

    beforeEach(async () => {
        ws = await workspaceFixture();
        calls = [];
    });
    afterEach(() => ws.cleanup());

    const make = (gateScript?: GateScript) => {
        const batches: EventBatch[] = [];
        const outbox = new Outbox(async (batch) => {
            batches.push(batch);

            return {};
        }, { flushIntervalMs: 1 });
        const { app } = buildApp({
            daemonToken: TOKEN,
            outbox,
            queryFactory: fakeFactory(async function* () {}).factory,
            environment: { workspaceDir: ws.layout.root, agentsDir: join(ws.tmp, 'agents') },
            credentialHelper: '/bin/true',
            gateScript,
        });

        return { app, outbox, events: () => batches.flatMap((b) => b.events) };
    };

    it('runs in the task\'s worktree with its base commit and serves the fix prompt', async () => {
        const worktree = await prepareWorktree(ws.layout, { slug: 'fix-login', branch: 'argos/fix-login', baseBranch: 'main', env: {} });
        const { app, outbox, events } = make(fakeScript(calls));

        expect((await app.inject({ method: 'POST', url: '/worktrees/fix-login/gates/run', headers: auth, payload: { run_id: '../x' } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/worktrees/nope/gates/run', headers: auth, payload: { run_id: 'r1' } })).json().error.code).toBe('unknown_worktree');
        const run = await app.inject({ method: 'POST', url: '/worktrees/fix-login/gates/run', headers: auth, payload: { run_id: 'r1', base_commit: worktree.baseCommit } });
        expect(run.statusCode).toBe(202);
        await waitFor(() => events().some((e) => e.type === 'gates.completed'));

        expect(calls).toContainEqual(['--dir', worktree.path, '--run', 'r1']);
        // One gate only: passed on to run-gates.sh.
        const single = await app.inject({ method: 'POST', url: '/worktrees/fix-login/gates/run', headers: auth, payload: { run_id: 'r2', only: 'pest' } });
        expect(single.statusCode).toBe(202);
        await waitFor(() => calls.some((c) => c.includes('r2')));
        expect(calls).toContainEqual(['--dir', worktree.path, '--run', 'r2', '--only', 'pest']);
        expect((await app.inject({ method: 'POST', url: '/worktrees/fix-login/gates/run', headers: auth, payload: { run_id: 'r3', only: '../x' } })).statusCode).toBe(422);
        const started = events().find((e) => e.type === 'gates.started')!;
        expect(started.task_id).toBeNull();
        expect(started.data).toEqual({ run_id: 'r1', baseline: 'missing' });

        const prompt = await app.inject({ method: 'GET', url: '/worktrees/fix-login/gates/fix-prompt?gate=pest&run_id=r1', headers: auth });
        expect(prompt.json()).toEqual({ prompt: '# Quality-Gate-Fix: pest\n' });
        expect(calls).toContainEqual(['--dir', worktree.path, '--fix-prompt', 'pest', 'r1']);
        expect((await app.inject({ method: 'GET', url: '/gates', headers: auth })).json()).toEqual({ running: null });

        mkdirSync(join(worktree.path, '.agent', 'logs'), { recursive: true });
        writeFileSync(join(worktree.path, '.agent', 'logs', 'pest.r1.log'), 'line 1\nFAILED LoginTest\n');
        const log = await app.inject({ method: 'GET', url: '/worktrees/fix-login/gates/log?gate=pest&run_id=r1', headers: auth });
        expect(log.json()).toEqual({ log: 'line 1\nFAILED LoginTest\n', truncated: false, size: 24 });
        expect((await app.inject({ method: 'GET', url: '/worktrees/fix-login/gates/log?gate=../x&run_id=r1', headers: auth })).statusCode).toBe(422);
        expect((await app.inject({ method: 'GET', url: '/worktrees/fix-login/gates/log?gate=pest&run_id=../x', headers: auth })).statusCode).toBe(422);
        expect((await app.inject({ method: 'GET', url: '/worktrees/nope/gates/log?gate=pest&run_id=r1', headers: auth })).json().error.code).toBe('unknown_worktree');
        outbox.stop();
    }, 20_000);

    it('answers 501 when no gate script is configured', async () => {
        await prepareWorktree(ws.layout, { slug: 'fix-login', branch: 'argos/fix-login', baseBranch: 'main', env: {} });
        const { app, outbox } = make();

        expect((await app.inject({ method: 'POST', url: '/worktrees/fix-login/gates/run', headers: auth, payload: { run_id: 'r1' } })).statusCode).toBe(501);
        expect((await app.inject({ method: 'GET', url: '/gates', headers: auth })).statusCode).toBe(501);
        outbox.stop();
    }, 20_000);
});
