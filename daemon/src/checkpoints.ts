import { copyFile, mkdtemp, rm, stat, unlink, utimes } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { isAbsolute, join, normalize } from 'node:path';
import { CodedError } from './errors.js';
import { truncateUtf8 } from './events.js';
import { git, GitError } from './git.js';
import { ID_CHARS, ID_PATTERN } from './ids.js';

const CHECKPOINT_PREFIX = 'refs/argos/checkpoints';

/** Checkpoints kept per task; older refs are deleted. */
export const MAX_CHECKPOINTS = 200;

export interface Checkpoint {
    ref: string;
    n: number;
    sha: string;
    /** HEAD when the checkpoint was taken (null in a repo without commits). */
    head: string | null;
    label: string;
}

export type CheckpointErrorCode = 'invalid_task' | 'invalid_ref' | 'unknown_checkpoint';

export class CheckpointError extends CodedError {
    constructor(code: CheckpointErrorCode, message: string) {
        super(code === 'unknown_checkpoint' ? 404 : 422, code, message);
    }
}

const AUTHOR_ENV = {
    GIT_AUTHOR_NAME: 'Argos',
    GIT_AUTHOR_EMAIL: 'checkpoints@argos.invalid',
    GIT_COMMITTER_NAME: 'Argos',
    GIT_COMMITTER_EMAIL: 'checkpoints@argos.invalid',
};

const REF_PATTERN = new RegExp(`^${CHECKPOINT_PREFIX}/(${ID_CHARS})/(\\d{1,9})$`);

/**
 * Per-turn snapshots of the working tree (index + files, untracked but not
 * ignored included), stored as commit objects under
 * `refs/argos/checkpoints/<task>/<n>` — branch, index and working tree
 * stay untouched. Operations are serialized per workspace.
 */
export class CheckpointStore {
    private chain: Promise<unknown> = Promise.resolve();

    constructor(
        private readonly dir: string,
        private readonly env: NodeJS.ProcessEnv = process.env,
    ) {}

    create(taskId: string, label: string): Promise<Checkpoint> {
        return this.serial(() => this.createNow(taskId, label));
    }

    /**
     * Reset the working tree to a checkpoint. A checkpoint of the current
     * state is taken first, so the restore itself can be undone. HEAD is
     * left alone; commits made since stay.
     */
    restore(ref: string): Promise<{ before: Checkpoint; removed: string[] }> {
        const { taskId } = parseRef(ref);

        return this.serial(async () => {
            const target = await this.treeOf(ref);
            const before = await this.createNow(taskId, 'before restore');

            // Files that exist now but not in the checkpoint go away.
            const added = (await this.git(['diff', '--name-only', '--no-renames', '--diff-filter=A', '-z', target, `${before.sha}^{tree}`])).stdout
                .split('\0')
                .filter((path) => path !== '' && isInside(path));

            await this.git(['read-tree', '-u', '--reset', target]);
            for (const path of added) {
                await unlink(join(this.dir, path)).catch(() => {});
            }
            // Index back to HEAD: changes show as unstaged, new files as untracked.
            if (before.head !== null) {
                await this.git(['reset', '-q']);
            } else {
                await this.git(['read-tree', '--empty']);
            }

            return { before, removed: added };
        });
    }

    /** Changes from the checkpoint to the current state (what a restore undoes). */
    diff(ref: string, maxBytes = 512 * 1024): Promise<{ diff: string; truncated: boolean; head: string | null }> {
        parseRef(ref);

        return this.serial(async () => {
            const target = await this.treeOf(ref);
            const current = await this.snapshotTree();
            const diff = (await this.git(['diff', '--no-color', '--find-renames', target, current])).stdout;
            const bounded = truncateUtf8(diff, maxBytes);

            return { diff: bounded.text, truncated: bounded.truncated, head: await this.head() };
        });
    }

    async list(taskId: string): Promise<{ ref: string; n: number }[]> {
        const out = (await this.git(['for-each-ref', '--format=%(refname)', `${CHECKPOINT_PREFIX}/${taskId}/`])).stdout;

        return out
            .split('\n')
            .filter((line) => REF_PATTERN.test(line))
            .map((ref) => ({ ref, n: parseRef(ref).n }))
            .sort((a, b) => a.n - b.n);
    }

    private async createNow(taskId: string, label: string): Promise<Checkpoint> {
        if (!ID_PATTERN.test(taskId)) {
            throw new CheckpointError('invalid_task', 'Invalid task id');
        }
        const existing = await this.list(taskId);
        const n = (existing.at(-1)?.n ?? 0) + 1;
        const ref = `${CHECKPOINT_PREFIX}/${taskId}/${n}`;

        const tree = await this.snapshotTree();
        const head = await this.head();
        const message = `argos checkpoint ${taskId}/${n}${label !== '' ? `: ${label}` : ''}`;
        const sha = (await this.git(['commit-tree', tree, ...(head !== null ? ['-p', head] : []), '-m', message], AUTHOR_ENV)).stdout.trim();
        await this.git(['update-ref', ref, sha]);

        await this.prune([...existing, { ref, n }]);

        return { ref, n, sha, head, label };
    }

    /** Tree of index + working tree without touching the real index. */
    private async snapshotTree(): Promise<string> {
        const scratch = await mkdtemp(join(tmpdir(), 'argos-checkpoint-'));
        const index = join(scratch, 'index');
        try {
            const gitDir = (await this.git(['rev-parse', '--absolute-git-dir'])).stdout.trim();
            // Start from the real index: keeps staged state and its stat cache.
            await copyIndex(join(gitDir, 'index'), index);
            await this.git(['add', '-A'], { GIT_INDEX_FILE: index });

            return (await this.git(['write-tree'], { GIT_INDEX_FILE: index })).stdout.trim();
        } finally {
            await rm(scratch, { recursive: true, force: true });
        }
    }

    private async head(): Promise<string | null> {
        try {
            return (await this.git(['rev-parse', '--verify', '-q', 'HEAD'])).stdout.trim() || null;
        } catch {
            return null;
        }
    }

    private async treeOf(ref: string): Promise<string> {
        try {
            return (await this.git(['rev-parse', '--verify', '-q', `${ref}^{tree}`])).stdout.trim();
        } catch (error) {
            if (error instanceof GitError) {
                throw new CheckpointError('unknown_checkpoint', `Checkpoint ${ref} does not exist`);
            }
            throw error;
        }
    }

    private async prune(refs: { ref: string; n: number }[]): Promise<void> {
        const excess = refs.slice(0, Math.max(0, refs.length - MAX_CHECKPOINTS));
        if (excess.length === 0) {
            return;
        }
        for (const { ref } of excess) {
            await this.git(['update-ref', '-d', ref]);
        }
        void this.git(['gc', '--auto', '--quiet']).catch(() => {});
    }

    private git(args: string[], extraEnv: Record<string, string> = {}) {
        return git(args, this.dir, { ...this.env, ...extraEnv });
    }

    private serial<T>(operation: () => Promise<T>): Promise<T> {
        const run = this.chain.then(operation, operation);
        this.chain = run.catch(() => {});

        return run;
    }
}

/** Split a checkpoint ref; throws for anything outside the namespace. */
export function parseRef(ref: string): { taskId: string; n: number } {
    const match = REF_PATTERN.exec(ref);
    if (match === null) {
        throw new CheckpointError('invalid_ref', 'Not a checkpoint ref');
    }

    return { taskId: match[1]!, n: Number(match[2]) };
}

/**
 * Copy an index file keeping its mtime. Git treats entries modified in the
 * same timestamp tick as the index as "racily clean" and re-hashes them; a
 * fresh mtime on the copy would make a same-size edit look unchanged.
 */
async function copyIndex(from: string, to: string): Promise<void> {
    try {
        const { atime, mtime } = await stat(from);
        await copyFile(from, to);
        await utimes(to, atime, mtime);
    } catch {
        // No index yet (fresh repo): `git add` builds one.
    }
}

function isInside(path: string): boolean {
    const normalized = normalize(path);

    return !isAbsolute(normalized) && !normalized.startsWith('..');
}
