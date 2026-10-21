import { execFile } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
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

/**
 * Remove a task's worktree and its local branch. The remote branch (and any
 * pull request) stays.
 */
export async function removeWorktree(layout: Layout, slug: string): Promise<void> {
    const dir = worktreeDir(layout, slug);
    const branch = existsSync(dir) ? (await gitUnchecked(['symbolic-ref', '--quiet', '--short', 'HEAD'], dir)).stdout.trim() : '';
    await gitUnchecked(['worktree', 'remove', '--force', dir], layout.mainDir);
    await gitUnchecked(['worktree', 'prune'], layout.mainDir);
    if (branch !== '') {
        await gitUnchecked(['branch', '-D', branch], layout.mainDir);
    }
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
            execFile(script, [], { cwd: dir, env: childEnv, timeout: timeoutMs, maxBuffer: 32 * 1024 * 1024, encoding: 'utf8' }, (error, stdout, stderr) => {
                const code = error === null ? 0 : typeof error.code === 'number' ? error.code : 1;
                resolve({ code, output: `${stdout}${stderr}` });
            });
        });
}
