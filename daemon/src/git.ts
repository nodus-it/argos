import { execFile } from 'node:child_process';

export interface GitResult {
    stdout: string;
    stderr: string;
}

export class GitError extends Error {
    constructor(
        message: string,
        readonly stderr: string,
        readonly exitCode: number | null,
    ) {
        super(message);
    }
}

/**
 * Run git with an argument array (never a shell string). Output is forced to
 * the C locale so error classification does not depend on the host language.
 */
export function git(args: string[], cwd: string, env: NodeJS.ProcessEnv = process.env, timeoutMs = 600_000): Promise<GitResult> {
    return new Promise((resolve, reject) => {
        execFile('git', args, { cwd, env: { ...env, GIT_TERMINAL_PROMPT: '0', LC_ALL: 'C', LANGUAGE: 'C' }, timeout: timeoutMs, maxBuffer: 64 * 1024 * 1024 }, (error, stdout, stderr) => {
            if (error !== null) {
                const code = typeof (error as { code?: unknown }).code === 'number' ? ((error as { code: number }).code) : null;
                reject(new GitError(`git ${args[0] ?? ''} failed`, String(stderr), code));

                return;
            }
            resolve({ stdout: String(stdout), stderr: String(stderr) });
        });
    });
}

export type FetchFailure = 'auth' | 'branch_not_found' | 'network' | 'unknown';

/** Port of `_concept_classify_fetch_err` (worker/phases/concept.sh). */
export function classifyGitError(stderr: string): FetchFailure {
    const text = stderr.toLowerCase();
    if (/couldn't find remote ref|remote branch .* not found|not a valid ref|unknown revision|did not match any/.test(text)) {
        return 'branch_not_found';
    }
    if (/authentication failed|could not read username|permission denied|access denied|403|401|invalid username or password|terminal prompts disabled/.test(text)) {
        return 'auth';
    }
    if (/could not resolve host|connection timed out|connection refused|network is unreachable|failed to connect|operation timed out|ssl/.test(text)) {
        return 'network';
    }

    return 'unknown';
}
