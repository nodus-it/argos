import { describe, expect, it } from 'vitest';
import { guardDecision, outsidePath } from '../src/guard.js';

const root = '/workspace/tasks/fix-login';

describe('worktree guard', () => {
    it('allows file tools inside the worktree', () => {
        expect(outsidePath('Read', { file_path: `${root}/app/User.php` }, root)).toBeNull();
        expect(outsidePath('Write', { file_path: root }, root)).toBeNull();
        expect(outsidePath('NotebookEdit', { notebook_path: `${root}/nb.ipynb` }, root)).toBeNull();
        expect(outsidePath('Grep', { pattern: 'x', path: `${root}/src` }, root)).toBeNull();
        expect(outsidePath('Glob', { pattern: '**/*.php' }, root)).toBeNull();
    });

    it('flags paths outside the worktree, including siblings with a common prefix', () => {
        expect(outsidePath('Edit', { file_path: '/workspace/tasks/other/app/User.php' }, root)).toBe('/workspace/tasks/other/app/User.php');
        expect(outsidePath('MultiEdit', { file_path: '/workspace/tasks/fix-login-2/a.php' }, root)).toBe('/workspace/tasks/fix-login-2/a.php');
        expect(outsidePath('Read', { file_path: '/workspace/repo/.env' }, root)).toBe('/workspace/repo/.env');
        expect(outsidePath('Glob', { pattern: '*', path: '/etc' }, root)).toBe('/etc');
    });

    it('resolves relative paths against the worktree', () => {
        expect(outsidePath('Read', { file_path: 'app/User.php' }, root)).toBeNull();
        expect(outsidePath('Read', { file_path: './app/../routes/web.php' }, root)).toBeNull();
        expect(outsidePath('Read', { file_path: '../other/.env' }, root)).toBe('/workspace/tasks/other/.env');
    });

    it('always allows /tmp', () => {
        expect(outsidePath('Write', { file_path: '/tmp/scratch.txt' }, root)).toBeNull();
        expect(outsidePath('Read', { file_path: '/tmp' }, root)).toBeNull();
        expect(outsidePath('Read', { file_path: '/tmpfoo/x' }, root)).toBe('/tmpfoo/x');
    });

    it('ignores non-file tools and odd inputs', () => {
        expect(outsidePath('Bash', { command: 'cat /etc/passwd' }, root)).toBeNull();
        expect(outsidePath('WebFetch', { url: 'https://example.com' }, root)).toBeNull();
        expect(outsidePath('Read', null, root)).toBeNull();
        expect(outsidePath('Read', { file_path: 42 }, root)).toBeNull();
        expect(outsidePath('Read', { file_path: '' }, root)).toBeNull();
    });

    it('answers with the configured mode and a reason', () => {
        expect(guardDecision('Read', { file_path: `${root}/a` }, root, 'deny')).toBeNull();
        expect(guardDecision('Read', { file_path: '/etc/hosts' }, root, 'ask')).toMatchObject({ decision: 'ask' });
        const denied = guardDecision('Write', { file_path: '/etc/hosts' }, root, 'deny')!;
        expect(denied.decision).toBe('deny');
        expect(denied.reason).toContain('/etc/hosts');
        expect(denied.reason).toContain(root);
    });
});
