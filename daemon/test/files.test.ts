import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { FileIndex, readWorkspaceFile, score } from '../src/files.js';

describe('FileIndex', () => {
    let dir: string;
    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-files-'));
        execFileSync('git', ['init', '-q', dir], { env: { ...process.env, GIT_CONFIG_GLOBAL: '/dev/null' } });
        mkdirSync(join(dir, 'app/Http/Controllers'), { recursive: true });
        writeFileSync(join(dir, 'app/Http/Controllers/LoginController.php'), '');
        writeFileSync(join(dir, 'app/User.php'), '');
        writeFileSync(join(dir, 'README.md'), '');
        writeFileSync(join(dir, '.gitignore'), 'vendor/\n');
        mkdirSync(join(dir, 'vendor'), { recursive: true });
        writeFileSync(join(dir, 'vendor/autoload.php'), '');
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    it('finds files by fuzzy name and skips ignored ones', async () => {
        const index = new FileIndex(dir);

        expect((await index.search('logcon'))[0]).toBe('app/Http/Controllers/LoginController.php');
        expect(await index.search('user')).toContain('app/User.php');
        expect(await index.search('autoload')).toEqual([]);
        expect((await index.search('', 2)).length).toBe(2);
    });

    it('caches the file list until the TTL passes', async () => {
        let now = 0;
        const index = new FileIndex(dir, 1000, () => now);
        await index.search('readme');
        writeFileSync(join(dir, 'NEWFILE.md'), '');
        expect(await index.search('newfile')).toEqual([]);
        now = 2000;
        expect(await index.search('newfile')).toEqual(['NEWFILE.md']);
    });

    it('ranks exact names and prefixes highest', () => {
        expect(score('app/user.php', 'user.php')).toBe(1000);
        expect(score('app/user.php', 'use')).toBeGreaterThan(score('app/houses.php', 'use'));
        expect(score('a/b', 'zz')).toBe(0);
    });
});

describe('readWorkspaceFile', () => {
    let dir: string;
    let outside: string;
    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-read-'));
        outside = mkdtempSync(join(tmpdir(), 'argos-outside-'));
        mkdirSync(join(dir, 'routes'));
        writeFileSync(join(dir, 'routes/web.php'), "<?php\nRoute::view('/', 'welcome');\n");
        writeFileSync(join(outside, 'secret.txt'), 'nope');
        symlinkSync(join(outside, 'secret.txt'), join(dir, 'link.txt'));
    });
    afterEach(() => {
        rmSync(dir, { recursive: true, force: true });
        rmSync(outside, { recursive: true, force: true });
    });

    it('reads a file of the working tree', async () => {
        expect(await readWorkspaceFile(dir, 'routes/web.php')).toEqual({ content: "<?php\nRoute::view('/', 'welcome');\n", truncated: false });
    });

    it('refuses paths outside the workspace', async () => {
        await expect(readWorkspaceFile(dir, '../x')).rejects.toMatchObject({ code: 'invalid_path' });
        await expect(readWorkspaceFile(dir, '/etc/passwd')).rejects.toMatchObject({ code: 'invalid_path' });
        await expect(readWorkspaceFile(dir, 'link.txt')).rejects.toMatchObject({ code: 'invalid_path' });
        await expect(readWorkspaceFile(dir, 'missing.php')).rejects.toMatchObject({ code: 'not_found' });
    });
});
