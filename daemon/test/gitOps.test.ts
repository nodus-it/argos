import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { changedFiles, classifyPushError, commit, diffAgainst, fetch, parseStatus, push, PushError, status } from '../src/gitOps.js';

const env = { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null', LC_ALL: 'C' };
const run = (args: string[], cwd: string) => execFileSync('git', args, { cwd, encoding: 'utf8', env, stdio: ['ignore', 'pipe', 'pipe'] });
const id = ['-c', 'user.name=t', '-c', 'user.email=t@t'];

describe('git operations', () => {
    let root: string;
    let remote: string;
    let work: string;

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'argos-gitops-'));
        remote = join(root, 'remote.git');
        run(['init', '--bare', '-b', 'main', remote], root);
        work = join(root, 'work');
        run(['clone', remote, work], root);
        writeFileSync(join(work, 'README.md'), 'hello\n');
        run([...id, 'add', '.'], work);
        run([...id, 'commit', '-m', 'init'], work);
        run(['push', 'origin', 'main'], work);
        run(['checkout', '-b', 'ws/login'], work);
    });
    afterEach(() => rmSync(root, { recursive: true, force: true }));

    it('reports branch, dirtiness and files', async () => {
        writeFileSync(join(work, 'README.md'), 'changed\n');
        writeFileSync(join(work, 'new.txt'), 'new\n');

        const result = await status(work);

        expect(result.branch).toBe('ws/login');
        expect(result.dirty).toBe(true);
        expect(result.files).toEqual(expect.arrayContaining([{ path: 'README.md', status: 'M' }, { path: 'new.txt', status: '?' }]));
    });

    it('parses ahead/behind from porcelain v2', () => {
        const parsed = parseStatus('# branch.oid abc\n# branch.head ws/x\n# branch.upstream origin/ws/x\n# branch.ab +2 -1\n');
        expect(parsed).toMatchObject({ branch: 'ws/x', upstream: 'origin/ws/x', ahead: 2, behind: 1, dirty: false });
    });

    it('diffs committed, uncommitted and untracked changes against the base', async () => {
        writeFileSync(join(work, 'committed.txt'), 'c\n');
        run([...id, 'add', '.'], work);
        run([...id, 'commit', '-m', 'c'], work);
        writeFileSync(join(work, 'README.md'), 'edited\n');
        writeFileSync(join(work, 'untracked.txt'), 'u\n');

        const { diff, truncated } = await diffAgainst(work, 'origin/main');

        expect(truncated).toBe(false);
        expect(diff).toContain('committed.txt');
        expect(diff).toContain('+edited');
        expect(diff).toContain('untracked.txt');
        expect(diff).toContain('+u');
    });

    it('lists changed files with counts, generated flags and content hashes', async () => {
        writeFileSync(join(work, 'README.md'), 'hello\nworld\n');
        writeFileSync(join(work, 'composer.lock'), '{}\n');
        writeFileSync(join(work, 'new.txt'), 'a\nb\nc\n');
        run([...id, 'add', 'composer.lock'], work);
        run([...id, 'commit', '-m', 'lock'], work);

        const { files } = await changedFiles(work, 'origin/main');

        expect(files.map((f) => [f.path, f.status, f.additions, f.deletions, f.generated])).toEqual([
            ['composer.lock', 'A', 1, 0, true],
            ['new.txt', 'A', 3, 0, false],
            ['README.md', 'M', 1, 0, false],
        ].sort((a, b) => String(a[0]).localeCompare(String(b[0]))));
        const readme = files.find((f) => f.path === 'README.md');
        expect(readme?.hash).toMatch(/^[0-9a-f]{40}$/);

        writeFileSync(join(work, 'README.md'), 'hello\nagain\n');
        const after = (await changedFiles(work, 'origin/main')).files.find((f) => f.path === 'README.md');
        expect(after?.hash).not.toBe(readme?.hash);
    });

    it('diffs a single file', async () => {
        writeFileSync(join(work, 'README.md'), 'changed\n');
        writeFileSync(join(work, 'new.txt'), 'new\n');

        expect((await diffAgainst(work, 'origin/main', 512 * 1024, 'README.md')).diff).toContain('+changed');
        expect((await diffAgainst(work, 'origin/main', 512 * 1024, 'README.md')).diff).not.toContain('new.txt');
        expect((await diffAgainst(work, 'origin/main', 512 * 1024, 'new.txt')).diff).toContain('+new');
    });

    it('commits everything with the given author, and reports nothing to commit', async () => {
        writeFileSync(join(work, 'a.txt'), 'a\n');

        const result = await commit(work, 'feat: add a', 'Because.', { name: 'Ada', email: 'ada@example.com' });

        expect(result?.sha).toMatch(/^[0-9a-f]{40}$/);
        expect(run(['log', '-1', '--format=%an <%ae>|%s|%b'], work).trim()).toBe('Ada <ada@example.com>|feat: add a|Because.');
        expect(await commit(work, 'feat: nothing', '', { name: 'Ada', email: 'a@b' })).toBeNull();
    });

    it('pushes the branch and classifies a rejected push', async () => {
        writeFileSync(join(work, 'a.txt'), 'a\n');
        await commit(work, 'feat: a', '', { name: 'Ada', email: 'a@b' });
        await push(work, 'ws/login');
        expect(run(['ls-remote', '--heads', remote, 'ws/login'], root)).toContain('refs/heads/ws/login');

        // Someone else pushes to the branch first.
        const other = join(root, 'other');
        run(['clone', '-b', 'ws/login', remote, other], root);
        writeFileSync(join(other, 'b.txt'), 'b\n');
        run([...id, 'add', '.'], other);
        run([...id, 'commit', '-m', 'b'], other);
        run(['push', 'origin', 'ws/login'], other);

        writeFileSync(join(work, 'c.txt'), 'c\n');
        await commit(work, 'feat: c', '', { name: 'Ada', email: 'a@b' });
        await expect(push(work, 'ws/login')).rejects.toMatchObject({ code: 'rejected_non_fast_forward' });
        await expect(push(work, 'ws/login')).rejects.toBeInstanceOf(PushError);

        await fetch(work);
        expect((await status(work)).behind).toBeGreaterThanOrEqual(0);
    });

    it.each([
        [' ! [rejected]        ws/x -> ws/x (fetch first)', 'rejected_non_fast_forward'],
        ['fatal: Authentication failed for https://x', 'auth'],
        ['fatal: unable to access: Could not resolve host: github.com', 'network'],
        ['error: something odd', 'unknown'],
    ])('classifies %s', (stderr, expected) => {
        expect(classifyPushError(stderr)).toBe(expected);
    });
});
