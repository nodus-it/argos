import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { copyFile, mkdir, open, rm, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { strippedEnv } from './claudeEnv.js';
import { CodedError } from './errors.js';
import type { PendingEvent } from './events.js';
import { git } from './git.js';
import { taskEnv, type Layout } from './layout.js';

/** Runs `run-gates.sh` with the given arguments (and extra environment values). */
export type GateScript = (args: string[], timeoutMs: number, env?: Record<string, string>) => Promise<{ code: number; stdout: string; stderr: string }>;

export type BaselineState = 'missing' | 'running' | 'ready' | 'failed';

export class GatesBusyError extends CodedError {
    constructor(message: string) {
        super(409, 'gates_running', message);
    }
}

/** The gate script failed to answer a request (not a failing gate). */
export class GateScriptError extends CodedError {
    constructor(message: string) {
        super(502, 'gates_failed', message);
    }
}

/** Gate names as `run-gates.sh` reports them. */
export const GATE_NAME_PATTERN = /^[a-z_]{1,32}$/;

const RUN_TIMEOUT_MS = 30 * 60_000;
const BASELINE_TIMEOUT_MS = 30 * 60_000;
/** How much of a log travels in events and answers. */
export const LOG_TAIL_BYTES = 8 * 1024;
/** Cap of a full gate log served to the UI; longer logs keep their end. */
export const FULL_LOG_BYTES = 2 * 1024 * 1024;
const GATE_RESULTS = new Set(['pass', 'fail', 'skip']);
const BASELINE_FILES = ['pest-baseline.xml', 'phpstan-baseline.json'];
const COMMIT_PATTERN = /^[0-9a-f]{40}$/;

export function scriptRunner(script: string, cwd: string, extraEnv: Record<string, string> = {}): GateScript {
    // Never the daemon's or anyone's tokens.
    const env = { ...strippedEnv(), ...extraEnv };

    return (args, timeoutMs, extra = {}) =>
        new Promise((resolve) => {
            execFile(script, args, { cwd, env: { ...env, ...extra }, timeout: timeoutMs, maxBuffer: 16 * 1024 * 1024, encoding: 'utf8' }, (error, stdout, stderr) => {
                const code = error === null ? 0 : typeof error.code === 'number' ? error.code : 1;
                resolve({ code, stdout, stderr });
            });
        });
}

/**
 * Quality gates (`worker/lib/quality.sh` via `run-gates.sh`) for a task's
 * worktree. One run at a time per container; results travel as
 * workspace-level events (task_id = null) keyed by the manager's run id.
 *
 * Baselines (which tests/PHPStan errors already fail on the base) are kept
 * per base commit in the layout's baselines folder: captured once in the
 * main clone, then copied into each worktree built on that commit, so tasks
 * on the same base share one capture.
 */
export class GateRunner {
    private current: string | null = null;
    private readonly baselineStates = new Map<string, BaselineState>();
    private baselineChain: Promise<unknown> = Promise.resolve();

    constructor(
        private readonly script: GateScript,
        private readonly emit: (event: PendingEvent) => void,
        private readonly layout: Layout,
    ) {}

    get running(): string | null {
        return this.current;
    }

    /** Baseline state of a base commit. */
    baseline(commit: string): BaselineState {
        return this.baselineStates.get(commit) ?? (this.baselineOnDisk(commit) ? 'ready' : 'missing');
    }

    /**
     * Capture the baseline of `commit` in the main clone (background,
     * one capture at a time). The main clone is moved to the commit — it is
     * only a base, nobody works in it.
     */
    ensureBaseline(commit: string): Promise<void> {
        if (!COMMIT_PATTERN.test(commit) || this.baseline(commit) !== 'missing') {
            return Promise.resolve();
        }
        this.setBaseline(commit, 'running');

        const capture = this.baselineChain.then(async () => {
            try {
                await git(['checkout', '--detach', '--quiet', commit], this.layout.mainDir);
                for (const file of BASELINE_FILES) {
                    await rm(join(this.layout.mainDir, '.agent', file), { force: true });
                }
                const { code } = await this.script(['--dir', this.layout.mainDir, '--baseline'], BASELINE_TIMEOUT_MS);
                if (code !== 0) {
                    this.setBaseline(commit, 'failed');

                    return;
                }
                const target = join(this.layout.baselinesDir, commit);
                await mkdir(target, { recursive: true });
                for (const file of BASELINE_FILES) {
                    const source = join(this.layout.mainDir, '.agent', file);
                    if (existsSync(source)) {
                        await copyFile(source, join(target, file));
                    }
                }
                await writeFile(join(target, 'captured'), new Date().toISOString());
                this.setBaseline(commit, 'ready');
            } catch {
                this.setBaseline(commit, 'failed');
            }
        });
        this.baselineChain = capture;

        return capture;
    }

    /** Start a run in `dir`; throws GatesBusyError while another run is active. */
    start(runId: string, dir: string, baseCommit: string | null, only: string | null = null): Promise<void> {
        if (this.current !== null) {
            throw new GatesBusyError(`Gate run ${this.current} is still running`);
        }
        this.current = runId;

        return this.useBaseline(dir, baseCommit)
            .then((baseline) => {
                this.emit({ type: 'gates.started', data: { run_id: runId, baseline } });

                return this.execute(runId, dir, baseline, only);
            })
            .finally(() => {
                this.current = null;
            });
    }

    async fixPrompt(gate: string, runId: string, dir: string): Promise<string> {
        const { code, stdout, stderr } = await this.script(['--dir', dir, '--fix-prompt', gate, runId], 30_000);
        if (code !== 0) {
            throw new GateScriptError(stderr.trim() || `run-gates.sh exited with ${code}`);
        }

        return stdout;
    }

    /**
     * A gate's full log of a run (at most `maxBytes`, keeping the end).
     * Empty when the gate wrote no log (skipped, or the run is gone).
     */
    async log(gate: string, runId: string, dir: string, maxBytes: number = FULL_LOG_BYTES): Promise<{ log: string; truncated: boolean; size: number }> {
        const { code, stdout, stderr } = await this.script(['--dir', dir, '--log-path', gate, runId], 10_000);
        if (code !== 0) {
            throw new GateScriptError(stderr.trim() || `run-gates.sh exited with ${code}`);
        }
        const path = stdout.trim();
        if (path === '' || !existsSync(path)) {
            return { log: '', truncated: false, size: 0 };
        }
        const size = (await stat(path)).size;

        return { log: await readTail(path, maxBytes), truncated: size > maxBytes, size };
    }

    /** Copy the base commit's baseline into the worktree (or clear a stale one). */
    private async useBaseline(dir: string, baseCommit: string | null): Promise<BaselineState> {
        await mkdir(join(dir, '.agent'), { recursive: true });
        for (const file of BASELINE_FILES) {
            await rm(join(dir, '.agent', file), { force: true });
        }
        if (baseCommit === null || !COMMIT_PATTERN.test(baseCommit) || this.baseline(baseCommit) !== 'ready') {
            return baseCommit === null ? 'missing' : this.baseline(baseCommit);
        }
        for (const file of BASELINE_FILES) {
            const source = join(this.layout.baselinesDir, baseCommit, file);
            if (existsSync(source)) {
                await copyFile(source, join(dir, '.agent', file));
            }
        }

        return 'ready';
    }

    private async execute(runId: string, dir: string, baseline: BaselineState, only: string | null): Promise<void> {
        try {
            const args = ['--dir', dir, '--run', runId, ...(only !== null ? ['--only', only] : [])];
            const { code, stdout, stderr } = await this.script(args, RUN_TIMEOUT_MS, taskEnv(dir));
            const gates = parseGates(stdout);
            if (code !== 0 || gates === null) {
                this.emit({ type: 'gates.failed', data: { run_id: runId, message: tail(stderr.trim() || `run-gates.sh exited with ${code}`, 2000) } });

                return;
            }

            const logs: Record<string, string> = {};
            for (const [gate, result] of Object.entries(gates)) {
                if (result !== 'skip') {
                    logs[gate] = await this.logTail(gate, runId, dir);
                }
            }
            this.emit({ type: 'gates.completed', data: { run_id: runId, gates, logs, baseline } });
        } catch (error) {
            this.emit({ type: 'gates.failed', data: { run_id: runId, message: error instanceof Error ? error.message : String(error) } });
        }
    }

    private async logTail(gate: string, runId: string, dir: string): Promise<string> {
        const { code, stdout } = await this.script(['--dir', dir, '--log-path', gate, runId], 10_000);
        if (code !== 0) {
            return '';
        }

        return readTail(stdout.trim(), LOG_TAIL_BYTES);
    }

    private setBaseline(commit: string, state: BaselineState): void {
        this.baselineStates.set(commit, state);
        this.emit({ type: 'gates.baseline', data: { commit, state } });
    }

    private baselineOnDisk(commit: string): boolean {
        return COMMIT_PATTERN.test(commit) && existsSync(join(this.layout.baselinesDir, commit, 'captured'));
    }
}

/** The gates JSON, or null when the output is not a flat gate → result map. */
export function parseGates(stdout: string): Record<string, string> | null {
    try {
        const parsed: unknown = JSON.parse(stdout.trim());
        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return null;
        }
        const gates: Record<string, string> = {};
        for (const [gate, result] of Object.entries(parsed)) {
            if (typeof result !== 'string' || !GATE_RESULTS.has(result) || !GATE_NAME_PATTERN.test(gate)) {
                return null;
            }
            gates[gate] = result;
        }

        return gates;
    } catch {
        return null;
    }
}

async function readTail(path: string, bytes: number): Promise<string> {
    if (path === '' || !existsSync(path)) {
        return '';
    }
    const handle = await open(path, 'r');
    try {
        const { size } = await handle.stat();
        const start = Math.max(0, size - bytes);
        const buffer = Buffer.alloc(size - start);
        await handle.read(buffer, 0, buffer.length, start);
        // Drop a partial first line (and a split UTF-8 sequence with it).
        const text = buffer.toString('utf8');

        return start > 0 ? text.slice(text.indexOf('\n') + 1) : text;
    } finally {
        await handle.close();
    }
}

/** The last `chars` characters of a log. */
export function tail(text: string, chars: number = LOG_TAIL_BYTES): string {
    return text.length > chars ? text.slice(-chars) : text;
}
