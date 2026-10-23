import { realpath, readFile, stat } from 'node:fs/promises';
import { isAbsolute, relative, resolve } from 'node:path';
import { CodedError } from './errors.js';
import { git } from './git.js';

/**
 * Fuzzy file search for `@` mentions: tracked plus untracked (not ignored)
 * files, cached briefly so typing does not run git on every keystroke.
 */
export class FileIndex {
    private cache: { files: string[]; until: number } | null = null;

    constructor(
        private readonly dir: string,
        private readonly ttlMs = 10_000,
        private readonly now: () => number = Date.now,
    ) {}

    async search(query: string, limit = 20): Promise<string[]> {
        const files = await this.files();
        const needle = query.trim().toLowerCase();
        if (needle === '') {
            return files.slice(0, limit);
        }

        return files
            .map((path) => ({ path, score: score(path.toLowerCase(), needle) }))
            .filter((entry) => entry.score > 0)
            .sort((a, b) => b.score - a.score || a.path.length - b.path.length || a.path.localeCompare(b.path))
            .slice(0, limit)
            .map((entry) => entry.path);
    }

    private async files(): Promise<string[]> {
        if (this.cache !== null && this.cache.until > this.now()) {
            return this.cache.files;
        }
        const output = (await git(['ls-files', '--cached', '--others', '--exclude-standard', '-z'], this.dir)).stdout;
        const files = [...new Set(output.split('\0').filter((path) => path !== ''))];
        this.cache = { files, until: this.now() + this.ttlMs };

        return files;
    }
}

/**
 * Subsequence match with bonuses for contiguous runs, the file name and
 * segment starts. 0 = no match.
 */
export function score(path: string, needle: string): number {
    const name = path.slice(path.lastIndexOf('/') + 1);
    if (name === needle) {
        return 1000;
    }
    if (name.startsWith(needle)) {
        return 800 - name.length;
    }
    if (path.includes(needle)) {
        return 500 - path.length + (name.includes(needle) ? 100 : 0);
    }

    let total = 0;
    let run = 0;
    let at = 0;
    for (const char of needle) {
        const found = path.indexOf(char, at);
        if (found === -1) {
            return 0;
        }
        run = found === at ? run + 1 : 1;
        const segmentStart = found === 0 || path[found - 1] === '/' || path[found - 1] === '-' || path[found - 1] === '_' || path[found - 1] === '.';
        total += 1 + run * 2 + (segmentStart ? 5 : 0);
        at = found + 1;
    }

    return total;
}

/** Largest file the review reads in full (unchanged-lines expansion). */
const MAX_READ_BYTES = 1_000_000;

/**
 * Read a text file of the working tree for the review (expanding unchanged
 * lines). Only paths inside the workspace; symlinks out of it are refused.
 */
export async function readWorkspaceFile(dir: string, path: string): Promise<{ content: string; truncated: boolean }> {
    if (path === '' || isAbsolute(path) || path.split(/[\\/]/).includes('..')) {
        throw new CodedError(422, 'invalid_path', 'Path must be relative to the workspace');
    }
    const root = await realpath(dir);
    let real: string;
    try {
        real = await realpath(resolve(root, path));
    } catch {
        throw new CodedError(404, 'not_found', 'File not found');
    }
    const rel = relative(root, real);
    if (rel === '' || rel.startsWith('..') || isAbsolute(rel)) {
        throw new CodedError(422, 'invalid_path', 'Path must be inside the workspace');
    }
    const info = await stat(real);
    if (!info.isFile()) {
        throw new CodedError(404, 'not_found', 'Not a file');
    }
    const buffer = await readFile(real);

    return { content: buffer.subarray(0, MAX_READ_BYTES).toString('utf8'), truncated: buffer.length > MAX_READ_BYTES };
}
