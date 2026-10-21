import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { EventBatch, PendingEvent } from '../src/events.js';
import { GateRunner, GateScriptError, GatesBusyError, parseGates, type GateScript } from '../src/gates.js';
import { buildApp } from '../src/http.js';
import { Outbox } from '../src/outbox.js';
import { fakeFactory, waitFor } from './fakeQuery.js';

const TOKEN = 'daemon-token-123';
const auth = { authorization: `Bearer ${TOKEN}` };

describe('quality gates', () => {
    let dir: string;
    let calls: string[][];
    let emitted: PendingEvent[];

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-gates-'));
        mkdirSync(join(dir, '.agent', 'logs'), { recursive: true });
        calls = [];
        emitted = [];
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    /** A fake run-gates.sh: pest fails, pint passes, the rest skips. */
    const fakeScript = (overrides: Partial<Record<string, () => Promise<{ code: number; stdout: string; stderr: string }>>> = {}): GateScript =>
        async (args) => {
            calls.push(args);
            const override = overrides[args[0] ?? ''];
            if (override !== undefined) {
                return override();
            }
            switch (args[0]) {
                case '--run':
                    return { code: 0, stdout: JSON.stringify({ artisan: 'skip', pint: 'pass', pest: 'fail' }), stderr: '[INFO] noise' };
                case '--log-path':
                    return { code: 0, stdout: join(dir, '.agent', 'logs', `${args[1]}.${args[2]}.log`), stderr: '' };
                case '--fix-prompt':
                    return { code: 0, stdout: `# Quality-Gate-Fix: ${args[1]}\n`, stderr: '' };
                default:
                    return { code: 0, stdout: '', stderr: '' };
            }
        };

    const runner = (script: GateScript) => new GateRunner(script, (event) => emitted.push(event), dir);

    it('runs the gates and reports results with log tails', async () => {
        writeFileSync(join(dir, '.agent', 'logs', 'pest.r1.log'), 'x'.repeat(10_000) + '\nFAILED LoginTest\n');
        writeFileSync(join(dir, '.agent', 'logs', 'pint.r1.log'), 'PASS\n');

        await runner(fakeScript()).start('r1');

        expect(emitted.map((e) => e.type)).toEqual(['gates.started', 'gates.completed']);
        const completed = emitted[1]!.data as { gates: Record<string, string>; logs: Record<string, string> };
        expect(completed.gates).toEqual({ artisan: 'skip', pint: 'pass', pest: 'fail' });
        expect(Object.keys(completed.logs).sort()).toEqual(['pest', 'pint']);
        expect(completed.logs.pest).toBe('FAILED LoginTest\n');
        expect(completed.logs.pint).toBe('PASS\n');
    });

    it('allows one run at a time', async () => {
        let release!: () => void;
        const gate = new Promise<void>((resolve) => (release = resolve));
        const gates = runner(fakeScript({ '--run': async () => { await gate; return { code: 0, stdout: '{}', stderr: '' }; } }));

        const first = gates.start('r1');
        expect(() => gates.start('r2')).toThrow(GatesBusyError);
        expect(gates.running).toBe('r1');
        release();
        await first;
        expect(gates.running).toBeNull();
    });

    it('refuses a run while the baseline is being captured', async () => {
        let release!: () => void;
        const gate = new Promise<void>((resolve) => (release = resolve));
        const gates = runner(fakeScript({ '--baseline': async () => { await gate; return { code: 0, stdout: '', stderr: '' }; } }));

        const baseline = gates.startBaseline();
        expect(() => gates.start('r1')).toThrow(GatesBusyError);
        release();
        await baseline;
        await gates.start('r1');
        expect(gates.running).toBeNull();
    });

    it('reports a failed fix-prompt request as a gate script error', async () => {
        const gates = runner(fakeScript({ '--fix-prompt': async () => ({ code: 2, stdout: '', stderr: 'no such gate' }) }));

        await expect(gates.fixPrompt('pest', 'r1')).rejects.toMatchObject({ status: 502, code: 'gates_failed', message: 'no such gate' });
        expect(new GateScriptError('x')).toBeInstanceOf(Error);
    });

    it('reports a failed run with the script error', async () => {
        await runner(fakeScript({ '--run': async () => ({ code: 2, stdout: '', stderr: 'run-gates: invalid run id' }) })).start('r1');

        expect(emitted[1]).toEqual({ type: 'gates.failed', data: { run_id: 'r1', message: 'run-gates: invalid run id' } });
    });

    it('tracks the baseline capture', async () => {
        const gates = runner(fakeScript());
        expect(gates.baseline).toBe('missing');

        await gates.startBaseline();
        expect(gates.baseline).toBe('ready');
        expect(emitted.map((e) => e.data.state)).toEqual(['running', 'ready']);

        await runner(fakeScript({ '--baseline': async () => ({ code: 1, stdout: '', stderr: '' }) })).startBaseline();
        expect(emitted.at(-1)!.data.state).toBe('failed');
    });

    it('treats an existing baseline file as ready after a restart', () => {
        writeFileSync(join(dir, '.agent', 'pest-baseline.xml'), '<testsuites/>');

        expect(runner(fakeScript()).baseline).toBe('ready');
    });

    it('only accepts a flat gate → pass/fail/skip map', () => {
        expect(parseGates('{"pest":"fail"}')).toEqual({ pest: 'fail' });
        expect(parseGates('{"pest":"maybe"}')).toBeNull();
        expect(parseGates('[1]')).toBeNull();
        expect(parseGates('not json')).toBeNull();
    });

    it('exposes run, status and fix prompt over HTTP', async () => {
        const batches: EventBatch[] = [];
        const outbox = new Outbox(async (batch) => {
            batches.push(batch);

            return {};
        }, { flushIntervalMs: 1 });
        const { app } = buildApp({
            daemonToken: TOKEN,
            outbox,
            queryFactory: fakeFactory(async function* () {}).factory,
            environment: { workspaceDir: dir, agentsDir: join(dir, 'agents') },
            credentialHelper: '/bin/true',
            gateScript: fakeScript(),
        });

        expect((await app.inject({ method: 'POST', url: '/gates/run', headers: auth, payload: { run_id: '../x' } })).statusCode).toBe(422);
        expect((await app.inject({ method: 'POST', url: '/gates/run', headers: auth, payload: { run_id: 'r1' } })).statusCode).toBe(202);
        await waitFor(() => batches.flatMap((b) => b.events).some((e) => e.type === 'gates.completed'));
        const completed = batches.flatMap((b) => b.events).find((e) => e.type === 'gates.completed')!;
        expect(completed.task_id).toBeNull();

        const prompt = await app.inject({ method: 'GET', url: '/gates/fix-prompt?gate=pest&run_id=r1', headers: auth });
        expect(prompt.json()).toEqual({ prompt: '# Quality-Gate-Fix: pest\n' });
        expect((await app.inject({ method: 'GET', url: '/gates', headers: auth })).json()).toEqual({ running: null, baseline: 'missing' });
        outbox.stop();
    });

    it('answers 501 when no gate script is configured', async () => {
        const outbox = new Outbox(async () => ({}));
        const { app } = buildApp({
            daemonToken: TOKEN,
            outbox,
            queryFactory: fakeFactory(async function* () {}).factory,
            environment: { workspaceDir: dir, agentsDir: join(dir, 'agents') },
            credentialHelper: '/bin/true',
        });

        expect((await app.inject({ method: 'POST', url: '/gates/run', headers: auth, payload: { run_id: 'r1' } })).statusCode).toBe(501);
        outbox.stop();
    });
});
