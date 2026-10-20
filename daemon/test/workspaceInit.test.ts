import { execFileSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { classifyGitError } from '../src/git.js';
import { ensureExcluded, initWorkspace, WorkspaceInitError } from '../src/workspaceInit.js';

const run = (args: string[], cwd: string) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null' } });

describe('initWorkspace', () => {
    let root: string;
    let remote: string;
    let helper: string;

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'argos-init-'));
        remote = join(root, 'remote.git');
        run(['init', '--bare', '-b', 'main', remote], root);
        const seed = join(root, 'seed');
        run(['clone', remote, seed], root);
        writeFileSync(join(seed, 'README.md'), 'hello\n');
        run(['-c', 'user.name=t', '-c', 'user.email=t@t', 'add', '.'], seed);
        run(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-m', 'init'], seed);
        run(['push', 'origin', 'main'], seed);
        run(['checkout', '-b', 'ws/existing'], seed);
        writeFileSync(join(seed, 'feature.txt'), 'wip\n');
        run(['-c', 'user.name=t', '-c', 'user.email=t@t', 'add', '.'], seed);
        run(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-m', 'wip'], seed);
        run(['push', 'origin', 'ws/existing'], seed);

        helper = join(root, 'helper.sh');
        writeFileSync(helper, '#!/bin/sh\nexit 0\n');
        chmodSync(helper, 0o755);
    });
    afterEach(() => rmSync(root, { recursive: true, force: true }));

    const params = (branch: string) => ({ repoUrl: remote, baseBranch: 'main', branch, gitUserName: 'Ada', gitUserEmail: 'ada@example.com' });

    it('clones, creates the branch from base and configures identity and helper', async () => {
        const dir = join(root, 'ws');
        mkdirSync(dir);
        expect(await initWorkspace(dir, params('ws/new'), helper)).toBe('ready');
        expect(run(['rev-parse', '--abbrev-ref', 'HEAD'], dir).trim()).toBe('ws/new');
        expect(existsSync(join(dir, 'README.md'))).toBe(true);
        expect(run(['config', 'user.email'], dir).trim()).toBe('ada@example.com');
        expect(run(['config', '--get-all', 'credential.helper'], dir).trim()).toBe(helper);
        expect(readFileSync(join(dir, '.git', 'info', 'exclude'), 'utf8')).toContain('.agent/\n');
        expect(await initWorkspace(dir, params('ws/new'), helper)).toBe('already_initialized');
    });

    it('checks out an existing remote branch', async () => {
        const dir = join(root, 'ws2');
        await initWorkspace(dir, params('ws/existing'), helper);
        expect(existsSync(join(dir, 'feature.txt'))).toBe(true);
    });

    it('classifies a missing base branch', async () => {
        const dir = join(root, 'ws3');
        await expect(initWorkspace(dir, { ...params('ws/x'), baseBranch: 'nope' }, helper)).rejects.toMatchObject({ code: 'branch_not_found' });
    });

    it('refuses a non-empty directory that is not a checkout', async () => {
        const dir = join(root, 'ws4');
        mkdirSync(dir);
        writeFileSync(join(dir, 'stray'), '');
        await expect(initWorkspace(dir, params('ws/x'), helper)).rejects.toBeInstanceOf(WorkspaceInitError);
    });

    it('adds exclude patterns only once', () => {
        const dir = join(root, 'ex');
        mkdirSync(join(dir, '.git', 'info'), { recursive: true });
        ensureExcluded(dir, '.agent/');
        ensureExcluded(dir, '.agent/');
        expect(readFileSync(join(dir, '.git', 'info', 'exclude'), 'utf8')).toBe('.agent/\n');
    });
});

describe('classifyGitError', () => {
    it.each([
        ['fatal: Authentication failed for https://x', 'auth'],
        ['fatal: could not read Username for https://github.com: terminal prompts disabled', 'auth'],
        ["fatal: couldn't find remote ref main", 'branch_not_found'],
        ["fatal: 'origin/nope' is not a commit and a branch 'x' cannot be created from it", 'unknown'],
        ['fatal: unable to access: Could not resolve host: github.com', 'network'],
    ])('%s → %s', (stderr, expected) => {
        expect(classifyGitError(stderr)).toBe(expected);
    });
});
