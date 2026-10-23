import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { cacheEnv, layoutFor, saveTaskEnv, taskEnv, taskEnvFile, worktreeDir } from '../src/layout.js';
import { prepareWorktree, removeWorktree, writeEnv } from '../src/worktrees.js';
import { commitAll, git, workspaceFixture, type WorkspaceFixture } from './workspaceFixture.js';

describe('task worktrees', () => {
    let ws: WorkspaceFixture;

    beforeEach(async () => {
        ws = await workspaceFixture();
    });
    afterEach(() => ws.cleanup());

    const prepare = (slug: string, branch: string) => prepareWorktree(ws.layout, { slug, branch, baseBranch: 'main', env: {} });

    it('starts a new branch from the freshly fetched base', async () => {
        // The base moved on after the workspace was cloned.
        writeFileSync(join(ws.seed, 'later.txt'), 'later\n');
        const newMain = commitAll(ws.seed, 'later');
        git(['push', '-q', 'origin', 'main'], ws.seed);

        const prepared = await prepare('fix-login', 'argos/fix-login');

        expect(prepared).toEqual({ path: join(ws.layout.tasksDir, 'fix-login'), baseCommit: newMain, created: true });
        expect(git(['symbolic-ref', '--short', 'HEAD'], prepared.path).trim()).toBe('argos/fix-login');
        expect(git(['rev-parse', 'HEAD'], prepared.path).trim()).toBe(newMain);
        expect(existsSync(join(prepared.path, 'later.txt'))).toBe(true);
        // Not tracking the base: a push must create the task's own branch.
        expect(git(['for-each-ref', '--format=%(upstream)', 'refs/heads/argos/fix-login'], ws.layout.mainDir).trim()).toBe('');
    }, 20_000);

    it('continues a branch that already exists on the remote', async () => {
        const prepared = await prepare('existing', 'argos/existing');

        expect(prepared.created).toBe(true);
        expect(prepared.baseCommit).toBe(ws.mainCommit);
        expect(existsSync(join(prepared.path, 'feature.txt'))).toBe(true);
        expect(git(['rev-parse', '--abbrev-ref', '@{upstream}'], prepared.path).trim()).toBe('origin/argos/existing');
    }, 20_000);

    it('keeps an existing worktree as it is on re-prepare', async () => {
        const first = await prepare('fix-login', 'argos/fix-login');
        writeFileSync(join(first.path, 'work.txt'), 'in progress\n');

        const again = await prepare('fix-login', 'argos/fix-login');

        expect(again).toEqual({ path: first.path, baseCommit: first.baseCommit, created: false });
        expect(readFileSync(join(again.path, 'work.txt'), 'utf8')).toBe('in progress\n');
    }, 20_000);

    it('recreates a worktree that was deleted by hand', async () => {
        const first = await prepare('fix-login', 'argos/fix-login');
        rmSync(first.path, { recursive: true, force: true });

        const again = await prepare('fix-login', 'argos/fix-login');

        expect(again.created).toBe(true);
        expect(existsSync(join(again.path, 'README.md'))).toBe(true);
    }, 20_000);

    it('removes the worktree and the local branch but keeps the remote branch', async () => {
        const prepared = await prepare('existing', 'argos/existing');

        await removeWorktree(ws.layout, 'existing');

        expect(existsSync(prepared.path)).toBe(false);
        expect(git(['branch', '--list', 'argos/existing'], ws.layout.mainDir).trim()).toBe('');
        expect(git(['ls-remote', '--heads', ws.remote, 'argos/existing'], ws.tmp).trim()).not.toBe('');
        // Removing twice is harmless.
        await removeWorktree(ws.layout, 'existing');
    }, 20_000);

    it('keeps work no remote holds under a backup ref before removing the worktree', async () => {
        const prepared = await prepare('unsaved', 'argos/unsaved');
        writeFileSync(join(prepared.path, 'committed.txt'), 'local commit\n');
        git(['add', 'committed.txt'], prepared.path);
        git(['-c', 'user.name=T', '-c', 'user.email=t@x', 'commit', '-qm', 'local only'], prepared.path);
        writeFileSync(join(prepared.path, 'draft.txt'), 'not committed\n');

        const ref = await removeWorktree(ws.layout, 'unsaved');

        expect(ref).toBe('refs/argos/backup/unsaved');
        expect(existsSync(prepared.path)).toBe(false);
        expect(git(['show', `${ref}:draft.txt`], ws.layout.mainDir)).toBe('not committed\n');
        expect(git(['show', `${ref}~1:committed.txt`], ws.layout.mainDir)).toBe('local commit\n');
    }, 20_000);

    it('needs no backup when everything is on the remote', async () => {
        await prepare('pushed', 'argos/pushed');

        expect(await removeWorktree(ws.layout, 'pushed')).toBeNull();
        expect(git(['for-each-ref', 'refs/argos/backup/'], ws.layout.mainDir).trim()).toBe('');
    }, 20_000);

    it('refuses an uninitialized workspace and invalid slugs', async () => {
        const empty = layoutFor(join(ws.tmp, 'empty'));
        await expect(prepareWorktree(empty, { slug: 'a', branch: 'argos/a', baseBranch: 'main', env: {} })).rejects.toMatchObject({ code: 'not_initialized' });
        await expect(prepare('../evil', 'argos/x')).rejects.toMatchObject({ status: 422, code: 'invalid_slug' });
        expect(() => worktreeDir(ws.layout, 'Upper')).toThrow();
    });

    it('classifies a missing base branch', async () => {
        await expect(prepareWorktree(ws.layout, { slug: 'x', branch: 'argos/x', baseBranch: 'nope', env: {} })).rejects.toMatchObject({ status: 422, code: 'branch_not_found' });
    }, 20_000);
});

describe('writeEnv', () => {
    let dir: string;

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-env-'));
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const env = () => readFileSync(join(dir, '.env'), 'utf8');

    it('starts from .env.example, replaces (also commented) keys and appends new ones', () => {
        writeFileSync(join(dir, '.env.example'), 'APP_NAME=Laravel\n# DB_DATABASE=laravel\nDB_HOST=127.0.0.1\n');

        writeEnv(dir, { DB_DATABASE: 'task_fix_login', REDIS_PREFIX: 'fix-login_' });

        expect(env()).toBe('APP_NAME=Laravel\nDB_DATABASE=task_fix_login\nDB_HOST=127.0.0.1\nREDIS_PREFIX=fix-login_\n');
        expect(readFileSync(join(dir, '.env.example'), 'utf8')).toContain('# DB_DATABASE=laravel');
    });

    it('updates an existing .env in place', () => {
        writeFileSync(join(dir, '.env.example'), 'APP_NAME=Example\n');
        writeFileSync(join(dir, '.env'), 'APP_NAME=Mine\nDB_DATABASE=old\n');

        writeEnv(dir, { DB_DATABASE: 'new' });
        writeEnv(dir, { DB_DATABASE: 'new' });

        expect(env()).toBe('APP_NAME=Mine\nDB_DATABASE=new\n');
    });

    it('quotes values that need it', () => {
        writeEnv(dir, { APP_NAME: 'My App', SECRET: 'a"b\\c', URL: 'http://db:3306/x' });

        expect(env()).toBe('APP_NAME="My App"\nSECRET="a\\"b\\\\c"\nURL=http://db:3306/x\n');
    });

    it('skips invalid keys and writes nothing when there is nothing to write', () => {
        writeEnv(dir, {});
        expect(existsSync(join(dir, '.env'))).toBe(false);

        writeEnv(dir, { 'bad-key': 'x', GOOD: 'y' });
        expect(env()).toBe('GOOD=y\n');
    });

    it('does not mistake a key for another key sharing its prefix', () => {
        writeFileSync(join(dir, '.env'), 'DB_HOST_READ=replica\n');

        writeEnv(dir, { DB_HOST: 'primary' });

        expect(env()).toBe('DB_HOST_READ=replica\nDB_HOST=primary\n');
    });
});

describe('layout', () => {
    it('places caches on the volume', () => {
        const layout = layoutFor('/workspace');
        expect(layout).toMatchObject({ mainDir: '/workspace/repo', tasksDir: '/workspace/tasks', baselinesDir: '/workspace/.argos/baselines' });
        expect(cacheEnv(layout)).toEqual({ YARN_CACHE_FOLDER: '/workspace/.cache/yarn', PNPM_STORE_DIR: '/workspace/.cache/pnpm' });
    });

    it('keeps the task env in the worktree and tolerates a missing or broken file', () => {
        const dir = mkdtempSync(join(tmpdir(), 'argos-taskenv-'));
        try {
            expect(taskEnv(dir)).toEqual({});
            saveTaskEnv(dir, { DB_DATABASE: 'task_fix_login', REDIS_PREFIX: 'fix-login_' });
            expect(taskEnvFile(dir)).toBe(join(dir, '.agent', 'task-env.json'));
            expect(taskEnv(dir)).toEqual({ DB_DATABASE: 'task_fix_login', REDIS_PREFIX: 'fix-login_' });

            writeFileSync(taskEnvFile(dir), '{"A":"x","B":1,"C":null}');
            expect(taskEnv(dir)).toEqual({ A: 'x' });
            writeFileSync(taskEnvFile(dir), '["A"]');
            expect(taskEnv(dir)).toEqual({});
            writeFileSync(taskEnvFile(dir), 'not json');
            expect(taskEnv(dir)).toEqual({});
        } finally {
            rmSync(dir, { recursive: true, force: true });
        }
    });
});

describe('taskEnv reserved keys', () => {
    it('never lets a task override tokens, the agent config or process basics', async () => {
        const { mkdtempSync, rmSync } = await import('node:fs');
        const { tmpdir } = await import('node:os');
        const { join } = await import('node:path');
        const { saveTaskEnv, taskEnv } = await import('../src/layout.js');
        const dir = mkdtempSync(join(tmpdir(), 'argos-env-'));
        saveTaskEnv(dir, { DB_DATABASE: 'task_x', CLAUDE_CODE_OAUTH_TOKEN: 'x', PATH: '/evil', ARGOS_DAEMON_TOKEN: 'x', HOME: '/x' });
        expect(taskEnv(dir)).toEqual({ DB_DATABASE: 'task_x' });
        rmSync(dir, { recursive: true, force: true });
    });
});
