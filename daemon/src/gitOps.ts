import { CodedError } from './errors.js';
import { truncateUtf8 } from './events.js';
import { AUTH_FAILURE, git, GitError, gitUnchecked, NETWORK_FAILURE } from './git.js';

export interface GitStatus {
    branch: string | null;
    upstream: string | null;
    ahead: number;
    behind: number;
    dirty: boolean;
    files: { path: string; status: string }[];
}

/** Parse `git status --porcelain=v2 --branch`. */
export function parseStatus(output: string): GitStatus {
    const status: GitStatus = { branch: null, upstream: null, ahead: 0, behind: 0, dirty: false, files: [] };

    for (const line of output.split('\n')) {
        if (line.startsWith('# branch.head ')) {
            const head = line.slice('# branch.head '.length).trim();
            status.branch = head === '(detached)' ? null : head;
        } else if (line.startsWith('# branch.upstream ')) {
            status.upstream = line.slice('# branch.upstream '.length).trim();
        } else if (line.startsWith('# branch.ab ')) {
            const match = line.match(/\+(\d+) -(\d+)/);
            if (match !== null) {
                status.ahead = Number(match[1]);
                status.behind = Number(match[2]);
            }
        } else if (line.startsWith('1 ') || line.startsWith('2 ')) {
            const parts = line.split(' ');
            // Ordinary (1) entries: path is the last field; renames (2) carry "path\torig".
            const path = (line.startsWith('2 ') ? parts.slice(9).join(' ') : parts.slice(8).join(' ')).split('\t')[0] ?? '';
            status.files.push({ path, status: (parts[1] ?? '..').replace(/\./g, '').slice(0, 1) || 'M' });
        } else if (line.startsWith('? ')) {
            status.files.push({ path: line.slice(2), status: '?' });
        } else if (line.startsWith('u ')) {
            status.files.push({ path: line.split(' ').slice(10).join(' '), status: 'U' });
        }
    }
    status.dirty = status.files.length > 0;

    return status;
}

export async function status(dir: string): Promise<GitStatus> {
    const result = await git(['status', '--porcelain=v2', '--branch', '--untracked-files=all'], dir);

    return parseStatus(result.stdout);
}

/**
 * Diff of everything the workspace changed against `base`: committed and
 * uncommitted changes since the merge base, plus untracked (not ignored)
 * files — what a pull request would contain once committed.
 */
export async function diffAgainst(dir: string, base: string, maxBytes = 512 * 1024): Promise<{ diff: string; truncated: boolean }> {
    let mergeBase = base;
    try {
        mergeBase = (await git(['merge-base', base, 'HEAD'], dir)).stdout.trim() || base;
    } catch {
        // No common history (or unknown base): diff against the ref itself.
    }

    let diff = (await git(['diff', '--no-color', '--find-renames', mergeBase], dir)).stdout;

    const untracked = (await git(['ls-files', '--others', '--exclude-standard', '-z'], dir)).stdout.split('\0').filter((path) => path !== '');
    for (const path of untracked) {
        if (Buffer.byteLength(diff) > maxBytes) {
            break;
        }
        diff += await untrackedDiff(dir, path);
    }

    const bounded = truncateUtf8(diff, maxBytes);

    return { diff: bounded.text, truncated: bounded.truncated };
}

/** Diff of one untracked file against /dev/null (`--no-index` exits 1 on differences). */
async function untrackedDiff(dir: string, path: string): Promise<string> {
    return (await gitUnchecked(['diff', '--no-color', '--no-index', '--', '/dev/null', path], dir)).stdout;
}

export async function fetch(dir: string): Promise<void> {
    await git(['fetch', '--prune', 'origin'], dir);
}

export async function commit(dir: string, subject: string, body: string, author: { name: string; email: string }): Promise<{ sha: string } | null> {
    await git(['add', '-A'], dir);
    const staged = await git(['diff', '--cached', '--name-only'], dir);
    if (staged.stdout.trim() === '') {
        return null;
    }

    const message = body.trim() === '' ? subject : `${subject}\n\n${body.trim()}`;
    await git(['-c', `user.name=${author.name}`, '-c', `user.email=${author.email}`, 'commit', '--no-verify', '-m', message], dir);
    const sha = (await git(['rev-parse', 'HEAD'], dir)).stdout.trim();

    return { sha };
}

export type PushFailure = 'rejected_non_fast_forward' | 'auth' | 'network' | 'unknown';

export class PushError extends CodedError {
    constructor(
        override readonly code: PushFailure,
        message: string,
    ) {
        super(409, code, message);
    }
}

export function classifyPushError(stderr: string): PushFailure {
    const text = stderr.toLowerCase();
    if (/non-fast-forward|fetch first|\[rejected\]|updates were rejected/.test(text)) {
        return 'rejected_non_fast_forward';
    }
    if (AUTH_FAILURE.test(text)) {
        return 'auth';
    }
    if (NETWORK_FAILURE.test(text)) {
        return 'network';
    }

    return 'unknown';
}

export async function push(dir: string, branch: string): Promise<void> {
    try {
        await git(['push', '--set-upstream', 'origin', `HEAD:refs/heads/${branch}`], dir);
    } catch (error) {
        if (error instanceof GitError) {
            throw new PushError(classifyPushError(error.stderr), error.stderr.trim().slice(0, 2000));
        }
        throw error;
    }
}
