import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { open } from 'node:fs/promises';
import { join } from 'node:path';
import { strippedEnv } from './claudeEnv.js';
import { CodedError } from './errors.js';
import type { PendingEvent } from './events.js';

/** Runs `run-gates.sh` with the given arguments. */
export type GateScript = (args: string[], timeoutMs: number) => Promise<{ code: number; stdout: string; stderr: string }>;

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
const LOG_TAIL_BYTES = 8 * 1024;
const GATE_RESULTS = new Set(['pass', 'fail', 'skip']);

export function scriptRunner(script: string, cwd: string): GateScript {
    // Never the daemon's or anyone's tokens.
    const env = strippedEnv();

    return (args, timeoutMs) =>
        new Promise((resolve) => {
            execFile(script, args, { cwd, env, timeout: timeoutMs, maxBuffer: 16 * 1024 * 1024, encoding: 'utf8' }, (error, stdout, stderr) => {
                const code = error === null ? 0 : typeof error.code === 'number' ? error.code : 1;
                resolve({ code, stdout, stderr });
            });
        });
}

/**
 * Quality gates for the workspace (`worker/lib/quality.sh` via
 * `run-gates.sh`). One run at a time; results travel as workspace-level
 * events (thread_id = null) keyed by the manager's run id.
 */
export class GateRunner {
    private current: string | null = null;
    private baselineState: BaselineState;

    constructor(
        private readonly script: GateScript,
        private readonly emit: (event: PendingEvent) => void,
        private readonly workspaceDir: string,
    ) {
        this.baselineState = this.baselineOnDisk() ? 'ready' : 'missing';
    }

    get running(): string | null {
        return this.current;
    }

    get baseline(): BaselineState {
        return this.baselineState;
    }

    /** Capture pest/phpstan baselines on the fresh checkout (background). */
    startBaseline(): Promise<void> {
        if (this.baselineState === 'running') {
            return Promise.resolve();
        }
        this.setBaseline('running');

        return this.script(['--baseline'], BASELINE_TIMEOUT_MS).then(
            ({ code }) => this.setBaseline(code === 0 ? 'ready' : 'failed'),
            () => this.setBaseline('failed'),
        );
    }

    /** Start a run; throws GatesBusyError while another run or the baseline capture is active. */
    start(runId: string): Promise<void> {
        if (this.current !== null) {
            throw new GatesBusyError(`Gate run ${this.current} is still running`);
        }
        if (this.baselineState === 'running') {
            throw new GatesBusyError('The quality-gate baseline is still being captured');
        }
        this.current = runId;
        this.emit({ type: 'gates.started', data: { run_id: runId, baseline: this.baselineState } });

        return this.execute(runId).finally(() => {
            this.current = null;
        });
    }

    async fixPrompt(gate: string, runId: string): Promise<string> {
        const { code, stdout, stderr } = await this.script(['--fix-prompt', gate, runId], 30_000);
        if (code !== 0) {
            throw new GateScriptError(stderr.trim() || `run-gates.sh exited with ${code}`);
        }

        return stdout;
    }

    private async execute(runId: string): Promise<void> {
        try {
            const { code, stdout, stderr } = await this.script(['--run', runId], RUN_TIMEOUT_MS);
            const gates = parseGates(stdout);
            if (code !== 0 || gates === null) {
                this.emit({ type: 'gates.failed', data: { run_id: runId, message: tail(stderr.trim() || `run-gates.sh exited with ${code}`, 2000) } });

                return;
            }

            const logs: Record<string, string> = {};
            for (const [gate, result] of Object.entries(gates)) {
                if (result !== 'skip') {
                    logs[gate] = await this.logTail(gate, runId);
                }
            }
            this.emit({ type: 'gates.completed', data: { run_id: runId, gates, logs, baseline: this.baselineState } });
        } catch (error) {
            this.emit({ type: 'gates.failed', data: { run_id: runId, message: error instanceof Error ? error.message : String(error) } });
        }
    }

    private async logTail(gate: string, runId: string): Promise<string> {
        const { code, stdout } = await this.script(['--log-path', gate, runId], 10_000);
        if (code !== 0) {
            return '';
        }

        return readTail(stdout.trim(), LOG_TAIL_BYTES);
    }

    private setBaseline(state: BaselineState): void {
        this.baselineState = state;
        this.emit({ type: 'gates.baseline', data: { state } });
    }

    private baselineOnDisk(): boolean {
        return ['pest-baseline.xml', 'phpstan-baseline.json'].some((file) => existsSync(join(this.workspaceDir, '.agent', file)));
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

function tail(text: string, chars: number): string {
    return text.length > chars ? text.slice(-chars) : text;
}
