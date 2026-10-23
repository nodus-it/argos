import { STRIPPED_ENV } from './claudeEnv.js';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { CodedError } from './errors.js';

/**
 * Where things live on the workspace volume:
 *
 *   /workspace/repo              main clone (default branch, only a base)
 *   /workspace/tasks/<slug>      one git worktree per task
 *   /workspace/.cache            package caches shared by all tasks
 *   /workspace/.argos/baselines  quality-gate baselines per base commit
 */
export interface Layout {
    root: string;
    mainDir: string;
    tasksDir: string;
    cacheDir: string;
    baselinesDir: string;
}

/** Task slugs as Argos names them (also the worktree folder name). */
export const SLUG_PATTERN = /^[a-z0-9](?:[a-z0-9-]{0,46}[a-z0-9])?$/;

export function layoutFor(root: string): Layout {
    return {
        root,
        mainDir: join(root, 'repo'),
        tasksDir: join(root, 'tasks'),
        cacheDir: join(root, '.cache'),
        baselinesDir: join(root, '.argos', 'baselines'),
    };
}

export function worktreeDir(layout: Layout, slug: string): string {
    if (!SLUG_PATTERN.test(slug)) {
        throw new CodedError(422, 'invalid_slug', 'Invalid task slug');
    }

    return join(layout.tasksDir, slug);
}

/**
 * Caches the tasks' package managers share. Composer and npm already use the
 * caches the container mounts; the others go to the workspace volume.
 */
export function cacheEnv(layout: Layout): Record<string, string> {
    return {
        YARN_CACHE_FOLDER: join(layout.cacheDir, 'yarn'),
        PNPM_STORE_DIR: join(layout.cacheDir, 'pnpm'),
    };
}

/** Keys a task's values may never override (tokens, the agent's config, the process basics). */
/** A task's values never set the secrets claude children are stripped of, nor the process basics. */
const RESERVED_ENV = new Set<string>([...STRIPPED_ENV, 'PATH', 'HOME', 'USER', 'SHELL', 'NODE_OPTIONS']);

/** Where a worktree keeps the task's environment values (see taskEnv()). */
export function taskEnvFile(dir: string): string {
    return join(dir, '.agent', 'task-env.json');
}

/**
 * The task's own values (database, Redis prefix, …) that override the
 * container's environment for everything running in its worktree —
 * Claude's tools, the gates and the setup. Real environment variables beat
 * `.env`, so without this every task would use the workspace's database.
 */
export function taskEnv(dir: string): Record<string, string> {
    try {
        const parsed: unknown = JSON.parse(readFileSync(taskEnvFile(dir), 'utf8'));
        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return {};
        }

        return Object.fromEntries(
            Object.entries(parsed).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && !RESERVED_ENV.has(entry[0]) && !entry[0].startsWith('ARGOS_')),
        );
    } catch {
        return {};
    }
}

export function saveTaskEnv(dir: string, env: Record<string, string>): void {
    mkdirSync(join(dir, '.agent'), { recursive: true });
    writeFileSync(taskEnvFile(dir), JSON.stringify(env));
}
