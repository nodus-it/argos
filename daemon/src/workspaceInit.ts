import { appendFileSync, existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { CodedError } from './errors.js';
import { classifyGitError, git, GitError } from './git.js';

export interface WorkspaceInitParams {
    repoUrl: string;
    baseBranch: string;
    branch: string;
    gitUserName: string;
    gitUserEmail: string;
}

export class WorkspaceInitError extends CodedError {
    constructor(code: string, message: string) {
        super(422, code, message);
    }
}

/**
 * Clone the project into the (empty) workspace volume and check out the
 * workspace branch. Idempotent: an existing checkout is left alone.
 *
 * The repository token never touches disk: git asks the Argos credential
 * helper, which fetches it from the manager on demand.
 */
export async function initWorkspace(dir: string, params: WorkspaceInitParams, credentialHelper: string): Promise<'ready' | 'already_initialized'> {
    if (existsSync(join(dir, '.git'))) {
        return 'already_initialized';
    }
    if (existsSync(dir) && readdirSync(dir).length > 0) {
        throw new WorkspaceInitError('workspace_not_empty', `${dir} is not empty and not a git checkout`);
    }

    const helperArgs = ['-c', 'credential.helper=', '-c', `credential.helper=${credentialHelper}`];

    try {
        await git([...helperArgs, 'clone', '--no-checkout', params.repoUrl, dir], '/');
        await git(['config', 'credential.helper', credentialHelper], dir);
        await git(['config', 'user.name', params.gitUserName], dir);
        await git(['config', 'user.email', params.gitUserEmail], dir);

        const remoteBranch = await git(['ls-remote', '--heads', 'origin', params.branch], dir);
        const baseBranch = await git(['ls-remote', '--heads', 'origin', params.baseBranch], dir);
        if (remoteBranch.stdout.trim() === '' && baseBranch.stdout.trim() === '') {
            throw new WorkspaceInitError('branch_not_found', `Base branch ${params.baseBranch} does not exist on the remote`);
        }
        if (remoteBranch.stdout.trim() !== '') {
            await git(['checkout', '-B', params.branch, `origin/${params.branch}`], dir);
        } else {
            await git(['checkout', '-b', params.branch, `origin/${params.baseBranch}`], dir);
        }
    } catch (error) {
        if (error instanceof GitError) {
            throw new WorkspaceInitError(classifyGitError(error.stderr), error.stderr.trim().slice(0, 2000));
        }
        throw error;
    }

    ensureExcluded(dir, '.agent/');

    return 'ready';
}

/** Mark a path as locally ignored (no churn in the project's .gitignore). */
export function ensureExcluded(dir: string, pattern: string): void {
    const exclude = join(dir, '.git', 'info', 'exclude');
    const current = existsSync(exclude) ? readFileSync(exclude, 'utf8') : '';
    if (current.split('\n').includes(pattern)) {
        return;
    }
    appendFileSync(exclude, `${current === '' || current.endsWith('\n') ? '' : '\n'}${pattern}\n`);
}
