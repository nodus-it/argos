import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { strippedEnv } from './claudeEnv.js';
import { CodedError } from './errors.js';
import { git, gitUnchecked, GitError, classifyGitError } from './git.js';
import { cacheEnv, worktreeDir, type Layout } from './layout.js';

export interface PrepareParams {
    slug: string;
    branch: string;
    baseBranch: string;
    /** Values written into the worktree's .env (task database, Redis prefix, …). */
    env: Record<string, string>;
}

export interface PreparedWorktree {
    path: string;
    /** The base branch commit the task started from. */
    baseCommit: string;
    created: boolean;
}

export class WorktreeError extends CodedError {
    constructor(code: string, message: string) {
        super(422, code, message);
    }
}

/**
 * Give a task its own working copy: a git worktree of the main clone on the
 * task's branch. The branch continues from the remote when it exists there
 * (a task picked up again), otherwise it starts at the freshly fetched base
 * branch. Idempotent: an existing worktree is kept as it is.
 */
export async function prepareWorktree(layout: Layout, params: PrepareParams): Promise<PreparedWorktree> {
    const dir = worktreeDir(layout, params.slug);
    if (!existsSync(join(layout.mainDir, '.git'))) {
        throw new WorktreeError('not_initialized', 'The workspace has no main clone yet');
    }

    try {
        await git(['fetch', '--prune', 'origin'], layout.mainDir);
        const base = await gitUnchecked(['rev-parse', '--verify', '--quiet', `refs/remotes/origin/${params.baseBranch}^{commit}`], layout.mainDir);
        if (base.exitCode !== 0) {
            throw new WorktreeError('branch_not_found', `Base branch ${params.baseBranch} does not exist on the remote`);
        }
        const baseCommit = base.stdout.trim();

        if (existsSync(join(dir, '.git'))) {
            return { path: dir, baseCommit: await mergeBase(dir, baseCommit), created: false };
        }

        mkdirSync(layout.tasksDir, { recursive: true });
        // A worktree that was removed by hand leaves its registration behind.
        await gitUnchecked(['worktree', 'prune'], layout.mainDir);
        const remote = await gitUnchecked(['rev-parse', '--verify', '--quiet', `refs/remotes/origin/${params.branch}`], layout.mainDir);
        if (remote.exitCode === 0) {
            await git(['worktree', 'add', '-B', params.branch, dir, `origin/${params.branch}`], layout.mainDir);
            await git(['branch', '--set-upstream-to', `origin/${params.branch}`, params.branch], dir);
        } else {
            await git(['worktree', 'add', '--no-track', '-B', params.branch, dir, baseCommit], layout.mainDir);
        }

        return { path: dir, baseCommit: await mergeBase(dir, baseCommit), created: true };
    } catch (error) {
        if (error instanceof GitError) {
            throw new WorktreeError(classifyGitError(error.stderr), error.stderr.trim().slice(0, 2000));
        }
        throw error;
    }
}

async function mergeBase(dir: string, baseCommit: string): Promise<string> {
    const result = await gitUnchecked(['merge-base', 'HEAD', baseCommit], dir);

    return result.exitCode === 0 && result.stdout.trim() !== '' ? result.stdout.trim() : baseCommit;
}

const BACKUP_AUTHOR_ENV = {
    ...process.env,
    GIT_AUTHOR_NAME: 'Argos',
    GIT_AUTHOR_EMAIL: 'backup@argos.invalid',
    GIT_COMMITTER_NAME: 'Argos',
    GIT_COMMITTER_EMAIL: 'backup@argos.invalid',
};

/** Where the work of a removed worktree that no remote holds is kept. */
export function backupRef(slug: string): string {
    return `refs/argos/backup/${slug}`;
}

/**
 * Keep what only this worktree holds before it goes: uncommitted changes
 * (tracked and untracked, .gitignore respected) become a commit on top of
 * HEAD, and when that commit — or HEAD itself — is on no remote branch it is
 * kept under `refs/argos/backup/<slug>` in the main clone. Returns the ref,
 * or null when everything was already pushed.
 */
export async function backupUnsavedWork(layout: Layout, slug: string): Promise<string | null> {
    const dir = worktreeDir(layout, slug);
    if (!existsSync(join(dir, '.git'))) {
        return null;
    }
    const headResult = await gitUnchecked(['rev-parse', '--verify', '--quiet', 'HEAD'], dir);
    let sha = headResult.exitCode === 0 ? headResult.stdout.trim() : '';

    const dirty = (await git(['status', '--porcelain', '--untracked-files=all'], dir)).stdout.trim() !== '';
    if (dirty) {
        const index = join(tmpdir(), `argos-backup-${slug}-${process.pid}-${Date.now()}`);
        const env = { ...BACKUP_AUTHOR_ENV, GIT_INDEX_FILE: index };
        try {
            if (sha !== '') {
                await git(['read-tree', sha], dir, env);
            }
            await git(['add', '-A'], dir, env);
            const tree = (await git(['write-tree'], dir, env)).stdout.trim();
            sha = (await git(['commit-tree', tree, ...(sha !== '' ? ['-p', sha] : []), '-m', `argos backup: uncommitted work of ${slug}`], dir, env)).stdout.trim();
        } finally {
            rmSync(index, { force: true });
        }
    }
    if (sha === '') {
        return null;
    }

    const onRemote = (await git(['for-each-ref', '--contains', sha, '--format=%(refname)', 'refs/remotes/'], dir)).stdout.trim() !== '';
    if (onRemote) {
        return null;
    }
    await git(['update-ref', backupRef(slug), sha], dir);

    return backupRef(slug);
}

/**
 * Remove a task's worktree and its local branch. The remote branch (and any
 * pull request) stays; work no remote holds is kept first (see
 * backupUnsavedWork). Returns the backup ref when there was such work.
 */
export async function removeWorktree(layout: Layout, slug: string, options: { backup?: boolean } = {}): Promise<string | null> {
    const dir = worktreeDir(layout, slug);
    const backup = options.backup === false ? null : await backupUnsavedWork(layout, slug);
    const branch = existsSync(dir) ? (await gitUnchecked(['symbolic-ref', '--quiet', '--short', 'HEAD'], dir)).stdout.trim() : '';
    await gitUnchecked(['worktree', 'remove', '--force', dir], layout.mainDir);
    await gitUnchecked(['worktree', 'prune'], layout.mainDir);
    if (branch !== '') {
        await gitUnchecked(['branch', '-D', branch], layout.mainDir);
    }

    return backup;
}

/**
 * Write the task's values into its `.env`: start from `.env.example` when
 * there is no `.env` yet, then replace or append each key. Values are quoted
 * when they need it.
 */
export function writeEnv(dir: string, values: Record<string, string>): void {
    const target = join(dir, '.env');
    const example = join(dir, '.env.example');
    if (!existsSync(target) && !existsSync(example) && Object.keys(values).length === 0) {
        return;
    }

    let lines = existsSync(target) ? readFileSync(target, 'utf8').split('\n') : existsSync(example) ? readFileSync(example, 'utf8').split('\n') : [];
    for (const [key, value] of Object.entries(values)) {
        if (!/^[A-Z][A-Z0-9_]*$/.test(key)) {
            continue;
        }
        const line = `${key}=${quoteEnv(value)}`;
        const index = lines.findIndex((l) => new RegExp(`^\\s*#?\\s*${key}=`).test(l));
        if (index === -1) {
            lines = [...lines.filter((l, i) => !(i === lines.length - 1 && l === '')), line, ''];
        } else {
            lines[index] = line;
        }
    }
    writeFileSync(target, lines.join('\n'));
}

function quoteEnv(value: string): string {
    return /^[A-Za-z0-9_./:@-]*$/.test(value) ? value : `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

/** Runs the setup script in a worktree; resolves with its exit code and output. */
export type SetupScript = (dir: string, env: Record<string, string>, timeoutMs: number) => Promise<{ code: number; output: string }>;

export const SETUP_TIMEOUT_MS = 30 * 60_000;

/**
 * `task-setup.sh` in the worktree: the project's `.argos/setup.sh`, else the
 * Laravel defaults. Never with the daemon's or anyone's tokens; package
 * caches are shared across tasks.
 */
export function setupRunner(script: string, layout: Layout): SetupScript {
    return (dir, env, timeoutMs) =>
        new Promise((resolve) => {
            const childEnv = { ...strippedEnv(), ...cacheEnv(layout), ...env };
            // stdout and stderr in the order they arrive: the log's tail must hold the failing step.
            const chunks: string[] = [];
            const child = spawn(script, [], { cwd: dir, env: childEnv });
            child.stdout.setEncoding('utf8').on('data', (chunk: string) => chunks.push(chunk));
            child.stderr.setEncoding('utf8').on('data', (chunk: string) => chunks.push(chunk));
            const timer = setTimeout(() => child.kill('SIGKILL'), timeoutMs);
            child.on('error', (error) => {
                clearTimeout(timer);
                resolve({ code: 1, output: `${chunks.join('')}${error.message}\n` });
            });
            child.on('close', (code) => {
                clearTimeout(timer);
                resolve({ code: code ?? 1, output: chunks.join('') });
            });
        });
}
