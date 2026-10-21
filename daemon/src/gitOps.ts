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
export async function diffAgainst(dir: string, base: string, maxBytes = 512 * 1024, path: string | null = null): Promise<{ diff: string; truncated: boolean }> {
    const mergeBase = await mergeBaseOf(dir, base);

    if (path !== null) {
        // One file (the review loads files one at a time): tracked, renamed or untracked.
        const untracked = (await git(['ls-files', '--others', '--exclude-standard', '-z', '--', path], dir)).stdout.split('\0').filter((p) => p !== '');
        const diff = untracked.length > 0
            ? await untrackedDiff(dir, path)
            : (await git(['diff', '--no-color', '--find-renames', mergeBase, '--', path, ...(await renameSources(dir, mergeBase, path))], dir)).stdout;
        const bounded = truncateUtf8(diff, maxBytes);

        return { diff: bounded.text, truncated: bounded.truncated };
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

async function mergeBaseOf(dir: string, base: string): Promise<string> {
    try {
        return (await git(['merge-base', base, 'HEAD'], dir)).stdout.trim() || base;
    } catch {
        // No common history (or unknown base): diff against the ref itself.
        return base;
    }
}

/** The old path of `path` when it was renamed since the merge base, so its diff pairs up. */
async function renameSources(dir: string, mergeBase: string, path: string): Promise<string[]> {
    const out = (await git(['diff', '--name-status', '-z', '--find-renames', mergeBase], dir)).stdout.split('\0');
    for (let i = 0; i < out.length; i++) {
        if ((out[i] ?? '').startsWith('R') && out[i + 2] === path && out[i + 1] !== undefined) {
            return [out[i + 1] as string];
        }
    }

    return [];
}

export interface ChangedFile {
    path: string;
    from_path: string | null;
    status: 'A' | 'M' | 'D' | 'R';
    additions: number;
    deletions: number;
    binary: boolean;
    generated: boolean;
    /** Blob hash of the working-tree content (empty for deleted files): changes when the file does. */
    hash: string;
}

/** Lock files and build output whose diff nobody reviews line by line. */
const GENERATED = [/(^|\/)(composer|package-lock|yarn|pnpm-lock|Cargo|Gemfile|poetry|bun)\.lock(b)?$/, /(^|\/)package-lock\.json$/, /(^|\/)pnpm-lock\.yaml$/, /\.min\.(js|css)$/, /\.map$/, /(^|\/)public\/(build|hot)\//, /(^|\/)dist\//];

/**
 * Files changed against `base` (committed, uncommitted and untracked) with
 * line counts — cheap enough to render a list of hundreds; the diff itself
 * is fetched per file.
 */
export async function changedFiles(dir: string, base: string): Promise<{ files: ChangedFile[] }> {
    const mergeBase = await mergeBaseOf(dir, base);
    const files = new Map<string, ChangedFile>();

    const nameStatus = (await git(['diff', '--name-status', '-z', '--find-renames', mergeBase], dir)).stdout.split('\0');
    for (let i = 0; i < nameStatus.length - 1;) {
        const code = nameStatus[i] ?? '';
        if (code === '') {
            i++;
            continue;
        }
        if (code.startsWith('R') || code.startsWith('C')) {
            const from = nameStatus[i + 1] ?? '';
            const to = nameStatus[i + 2] ?? '';
            files.set(to, { path: to, from_path: from, status: 'R', additions: 0, deletions: 0, binary: false, generated: false, hash: '' });
            i += 3;
        } else {
            const path = nameStatus[i + 1] ?? '';
            const status = code.startsWith('A') ? 'A' : code.startsWith('D') ? 'D' : 'M';
            files.set(path, { path, from_path: null, status, additions: 0, deletions: 0, binary: false, generated: false, hash: '' });
            i += 2;
        }
    }

    // --numstat -z: "add\tdel\tpath\0" or for renames "add\tdel\t\0from\0to\0".
    const numstat = (await git(['diff', '--numstat', '-z', '--find-renames', mergeBase], dir)).stdout.split('\0');
    for (let i = 0; i < numstat.length;) {
        const entry = numstat[i] ?? '';
        if (entry === '') {
            i++;
            continue;
        }
        const [add = '0', del = '0', rest = ''] = entry.split('\t');
        let path = rest;
        if (rest === '') {
            path = numstat[i + 2] ?? '';
            i += 3;
        } else {
            i += 1;
        }
        const file = files.get(path);
        if (file !== undefined) {
            file.binary = add === '-';
            file.additions = file.binary ? 0 : Number(add);
            file.deletions = file.binary ? 0 : Number(del);
        }
    }

    const untracked = (await git(['ls-files', '--others', '--exclude-standard', '-z'], dir)).stdout.split('\0').filter((p) => p !== '');
    for (const path of untracked) {
        const stats = await untrackedLines(dir, path);
        files.set(path, { path, from_path: null, status: 'A', additions: stats.lines, deletions: 0, binary: stats.binary, generated: false, hash: '' });
    }

    const paths = [...files.keys()];
    const existing = paths.filter((p) => files.get(p)?.status !== 'D');
    for (const chunk of chunks(existing, 200)) {
        const hashes = (await gitUnchecked(['hash-object', '--', ...chunk], dir)).stdout.trim().split('\n');
        chunk.forEach((p, n) => {
            const file = files.get(p);
            if (file !== undefined) {
                file.hash = hashes[n] ?? '';
            }
        });
    }
    for (const chunk of chunks(paths, 200)) {
        // -z output: path\0attr\0value\0 …
        const attrs = (await gitUnchecked(['check-attr', '-z', 'linguist-generated', '--', ...chunk], dir)).stdout.split('\0');
        for (let i = 0; i + 2 < attrs.length; i += 3) {
            const file = files.get(attrs[i] ?? '');
            if (file !== undefined && ['set', 'true'].includes(attrs[i + 2] ?? '')) {
                file.generated = true;
            }
        }
    }
    for (const file of files.values()) {
        file.generated ||= GENERATED.some((pattern) => pattern.test(file.path));
    }

    return { files: [...files.values()].sort((a, b) => a.path.localeCompare(b.path)) };
}

function chunks<T>(items: T[], size: number): T[][] {
    const out: T[][] = [];
    for (let i = 0; i < items.length; i += size) {
        out.push(items.slice(i, i + size));
    }

    return out;
}

async function untrackedLines(dir: string, path: string): Promise<{ lines: number; binary: boolean }> {
    const numstat = (await gitUnchecked(['diff', '--no-index', '--numstat', '--', '/dev/null', path], dir)).stdout.trim();
    const [add = '0'] = numstat.split('\t');

    return add === '-' ? { lines: 0, binary: true } : { lines: Number(add) || 0, binary: false };
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
