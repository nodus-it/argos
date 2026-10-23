import { execFileSync } from 'node:child_process';
import { chmodSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { layoutFor, type Layout } from '../src/layout.js';
import { initWorkspace } from '../src/workspaceInit.js';

export const gitEnv = { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null', LC_ALL: 'C' };
export const git = (args: string[], cwd: string): string => execFileSync('git', args, { cwd, encoding: 'utf8', env: gitEnv, stdio: ['ignore', 'pipe', 'pipe'] });
const id = ['-c', 'user.name=t', '-c', 'user.email=t@t'];

/** Commit everything in `dir` and return the new HEAD. */
export function commitAll(dir: string, message: string): string {
    git([...id, 'add', '-A'], dir);
    git([...id, 'commit', '-q', '-m', message], dir);

    return git(['rev-parse', 'HEAD'], dir).trim();
}

export interface WorkspaceFixture {
    tmp: string;
    remote: string;
    /** A plain clone of the remote to push changes from. */
    seed: string;
    layout: Layout;
    /** origin/main when the fixture was built. */
    mainCommit: string;
    cleanup(): void;
}

/**
 * A bare remote (main with README.md + .env.example, a pushed
 * `argos/existing` branch with feature.txt) and an initialized workspace
 * volume at `<tmp>/workspace` with its main clone in `repo/`.
 */
export async function workspaceFixture(): Promise<WorkspaceFixture> {
    const tmp = mkdtempSync(join(tmpdir(), 'argos-ws-'));
    const remote = join(tmp, 'remote.git');
    git(['init', '-q', '--bare', '-b', 'main', remote], tmp);
    const seed = join(tmp, 'seed');
    git(['clone', '-q', remote, seed], tmp);
    writeFileSync(join(seed, 'README.md'), 'hello\n');
    writeFileSync(join(seed, '.env.example'), 'APP_NAME=Laravel\n# DB_DATABASE=laravel\nDB_HOST=127.0.0.1\n');
    const mainCommit = commitAll(seed, 'init');
    git(['push', '-q', 'origin', 'main'], seed);
    git(['checkout', '-q', '-b', 'argos/existing'], seed);
    writeFileSync(join(seed, 'feature.txt'), 'wip\n');
    commitAll(seed, 'wip');
    git(['push', '-q', 'origin', 'argos/existing'], seed);
    git(['checkout', '-q', 'main'], seed);

    const helper = join(tmp, 'helper.sh');
    writeFileSync(helper, '#!/bin/sh\nexit 0\n');
    chmodSync(helper, 0o755);

    const layout = layoutFor(join(tmp, 'workspace'));
    const previous = process.env.GIT_CONFIG_GLOBAL;
    process.env.GIT_CONFIG_GLOBAL = '/dev/null';
    try {
        await initWorkspace(layout.mainDir, { repoUrl: remote, baseBranch: 'main', gitUserName: 'Ada', gitUserEmail: 'ada@example.com' }, helper);
    } finally {
        if (previous === undefined) {
            delete process.env.GIT_CONFIG_GLOBAL;
        } else {
            process.env.GIT_CONFIG_GLOBAL = previous;
        }
    }

    return { tmp, remote, seed, layout, mainCommit, cleanup: () => rmSync(tmp, { recursive: true, force: true }) };
}
