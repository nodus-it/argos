import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { CheckpointError, CheckpointStore, MAX_CHECKPOINTS, parseRef } from '../src/checkpoints.js';

const env = { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null', LC_ALL: 'C' };
const run = (args: string[], cwd: string) => execFileSync('git', args, { cwd, encoding: 'utf8', env, stdio: ['ignore', 'pipe', 'pipe'] });
const id = ['-c', 'user.name=t', '-c', 'user.email=t@t'];

describe('checkpoints', () => {
    let dir: string;
    let store: CheckpointStore;
    const read = (path: string) => readFileSync(join(dir, path), 'utf8');
    const statusLines = () => run(['status', '--porcelain'], dir).split('\n').filter((l) => l !== '').sort();

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-checkpoints-'));
        run(['init', '-q', '-b', 'main'], dir);
        writeFileSync(join(dir, '.gitignore'), 'vendor/\n');
        writeFileSync(join(dir, 'README.md'), 'hello\n');
        writeFileSync(join(dir, 'gone.txt'), 'tracked\n');
        run([...id, 'add', '.'], dir);
        run([...id, 'commit', '-q', '-m', 'init'], dir);
        store = new CheckpointStore(dir, env);
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    it('snapshots tracked, staged and untracked files without touching the repo', async () => {
        writeFileSync(join(dir, 'README.md'), 'edited\n');
        writeFileSync(join(dir, 'staged.txt'), 'staged\n');
        run(['add', 'staged.txt'], dir);
        writeFileSync(join(dir, 'new.txt'), 'untracked\n');
        mkdirSync(join(dir, 'vendor'));
        writeFileSync(join(dir, 'vendor', 'lib.php'), 'ignored\n');
        const before = statusLines();
        const head = run(['rev-parse', 'HEAD'], dir).trim();

        const checkpoint = await store.create('t1', 'turn 1');

        expect(checkpoint).toMatchObject({ ref: 'refs/argos/checkpoints/t1/1', n: 1, head, label: 'turn 1' });
        expect(statusLines()).toEqual(before);
        expect(run(['rev-parse', 'HEAD'], dir).trim()).toBe(head);
        expect(run(['branch', '--show-current'], dir).trim()).toBe('main');
        const files = run(['ls-tree', '-r', '--name-only', checkpoint.ref], dir).trim().split('\n').sort();
        expect(files).toEqual(['.gitignore', 'README.md', 'gone.txt', 'new.txt', 'staged.txt']);
        expect(run(['show', `${checkpoint.ref}:README.md`], dir)).toBe('edited\n');
        expect(run(['log', '-1', '--format=%P', checkpoint.ref], dir).trim()).toBe(head);
    });

    it('restores a checkpoint and can undo the restore', async () => {
        writeFileSync(join(dir, 'README.md'), 'turn one\n');
        writeFileSync(join(dir, 'kept.txt'), 'untracked at checkpoint\n');
        const checkpoint = await store.create('t1', '');

        // The agent keeps working: edits, deletes, adds.
        writeFileSync(join(dir, 'README.md'), 'turn two\n');
        rmSync(join(dir, 'gone.txt'));
        writeFileSync(join(dir, 'later.txt'), 'added later\n');
        writeFileSync(join(dir, 'kept.txt'), 'changed\n');

        const { before, removed } = await store.restore(checkpoint.ref);

        expect(read('README.md')).toBe('turn one\n');
        expect(read('gone.txt')).toBe('tracked\n');
        expect(read('kept.txt')).toBe('untracked at checkpoint\n');
        expect(existsSync(join(dir, 'later.txt'))).toBe(false);
        expect(removed).toEqual(['later.txt']);
        // Index is back to HEAD: edits unstaged, new files untracked.
        expect(statusLines()).toEqual([' M README.md', '?? kept.txt']);
        expect(before.n).toBe(2);
        expect(before.label).toBe('before restore');

        // Restore the restore.
        await store.restore(before.ref);
        expect(read('README.md')).toBe('turn two\n');
        expect(existsSync(join(dir, 'gone.txt'))).toBe(false);
        expect(read('later.txt')).toBe('added later\n');
        expect(read('kept.txt')).toBe('changed\n');
    });

    it('keeps commits made after the checkpoint', async () => {
        const checkpoint = await store.create('t1', '');
        writeFileSync(join(dir, 'feature.txt'), 'committed\n');
        run([...id, 'add', '.'], dir);
        run([...id, 'commit', '-q', '-m', 'feature'], dir);
        const head = run(['rev-parse', 'HEAD'], dir).trim();

        await store.restore(checkpoint.ref);

        expect(run(['rev-parse', 'HEAD'], dir).trim()).toBe(head);
        expect(existsSync(join(dir, 'feature.txt'))).toBe(false);
        expect(statusLines()).toEqual([' D feature.txt']);
    });

    it('shows what a restore would undo', async () => {
        const checkpoint = await store.create('t1', '');
        writeFileSync(join(dir, 'README.md'), 'changed\n');
        writeFileSync(join(dir, 'new.txt'), 'new\n');

        const { diff, truncated, head } = await store.diff(checkpoint.ref);

        expect(truncated).toBe(false);
        expect(diff).toContain('+changed');
        expect(diff).toContain('b/new.txt');
        expect(head).toBe(run(['rev-parse', 'HEAD'], dir).trim());
    });

    it(`keeps at most ${MAX_CHECKPOINTS} checkpoints per task`, async () => {
        const sha = run(['rev-parse', 'HEAD'], dir).trim();
        // Seed refs directly; creating 200 snapshots would only slow the test.
        const lines = Array.from({ length: MAX_CHECKPOINTS }, (_, i) => `create refs/argos/checkpoints/t1/${i + 1} ${sha}`).join('\n');
        execFileSync('git', ['update-ref', '--stdin'], { cwd: dir, env, input: `${lines}\n` });

        const created = await store.create('t1', '');

        const refs = await store.list('t1');
        expect(created.n).toBe(MAX_CHECKPOINTS + 1);
        expect(refs).toHaveLength(MAX_CHECKPOINTS);
        expect(refs[0]!.n).toBe(2);
        expect(await store.list('t2')).toEqual([]);
    });

    it('serializes concurrent checkpoints', async () => {
        const [a, b, c] = await Promise.all([store.create('t1', ''), store.create('t1', ''), store.create('t1', '')]);

        expect([a.n, b.n, c.n]).toEqual([1, 2, 3]);
    });

    it('works in a repository without commits', async () => {
        const empty = mkdtempSync(join(tmpdir(), 'argos-checkpoints-empty-'));
        try {
            run(['init', '-q'], empty);
            writeFileSync(join(empty, 'a.txt'), 'a\n');
            const fresh = new CheckpointStore(empty, env);
            const checkpoint = await fresh.create('t1', '');
            writeFileSync(join(empty, 'b.txt'), 'b\n');

            await fresh.restore(checkpoint.ref);

            expect(checkpoint.head).toBeNull();
            expect(existsSync(join(empty, 'b.txt'))).toBe(false);
            expect(readFileSync(join(empty, 'a.txt'), 'utf8')).toBe('a\n');
        } finally {
            rmSync(empty, { recursive: true, force: true });
        }
    });

    it('rejects refs outside the checkpoint namespace', async () => {
        expect(() => parseRef('refs/heads/main')).toThrow(CheckpointError);
        expect(() => parseRef('refs/argos/checkpoints/../../heads/main/1')).toThrow(CheckpointError);
        await expect(store.restore('refs/argos/checkpoints/t1/99')).rejects.toMatchObject({ code: 'unknown_checkpoint' });
    });
});
