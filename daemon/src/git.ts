import { execFile } from 'node:child_process';
import { CodedError } from './errors.js';

export interface GitResult {
    stdout: string;
    stderr: string;
}

/** A failed git command; reaches the manager as 502 with a classified code. */
export class GitError extends CodedError {
    constructor(
        message: string,
        readonly stderr: string,
        readonly exitCode: number | null,
    ) {
        super(502, classifyGitError(stderr), message);
    }
}

/**
 * Run git with an argument array (never a shell string), resolving even when
 * it exits non-zero. Output is forced to the C locale so error
 * classification does not depend on the host language.
 */
export function gitUnchecked(args: string[], cwd: string, env: NodeJS.ProcessEnv = process.env, timeoutMs = 600_000): Promise<GitResult & { exitCode: number | null; failed: boolean }> {
    return new Promise((resolve) => {
        execFile('git', args, { cwd, env: { ...env, GIT_TERMINAL_PROMPT: '0', LC_ALL: 'C', LANGUAGE: 'C' }, timeout: timeoutMs, maxBuffer: 64 * 1024 * 1024 }, (error, stdout, stderr) => {
            const exitCode = error === null ? 0 : typeof (error as { code?: unknown }).code === 'number' ? (error as { code: number }).code : null;
            resolve({ stdout: String(stdout), stderr: String(stderr), exitCode, failed: error !== null });
        });
    });
}

/** Run git; rejects with a GitError when it fails. */
export async function git(args: string[], cwd: string, env: NodeJS.ProcessEnv = process.env, timeoutMs = 600_000): Promise<GitResult> {
    const result = await gitUnchecked(args, cwd, env, timeoutMs);
    if (result.failed) {
        throw new GitError(`git ${args[0] ?? ''} failed`, result.stderr, result.exitCode);
    }

    return { stdout: result.stdout, stderr: result.stderr };
}

/** Credential problems, shared by fetch/clone and push classification. */
export const AUTH_FAILURE = /authentication failed|could not read username|permission|access denied|403|401|invalid username or password|terminal prompts disabled/;

/** Connectivity problems, shared by fetch/clone and push classification. */
export const NETWORK_FAILURE = /could not resolve host|connection timed out|connection refused|network is unreachable|failed to connect|operation timed out|ssl/;

export type FetchFailure = 'auth' | 'branch_not_found' | 'network' | 'unknown';

/** Classifies git fetch errors (auth vs. network vs. missing ref). */
export function classifyGitError(stderr: string): FetchFailure {
    const text = stderr.toLowerCase();
    if (/couldn't find remote ref|remote branch .* not found|not a valid ref|unknown revision|did not match any/.test(text)) {
        return 'branch_not_found';
    }
    if (AUTH_FAILURE.test(text)) {
        return 'auth';
    }
    if (NETWORK_FAILURE.test(text)) {
        return 'network';
    }

    return 'unknown';
}
