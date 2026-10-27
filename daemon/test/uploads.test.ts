import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { safeName, storeUploads, uploadNote } from '../src/uploads.js';

describe('uploads', () => {
    it('writes files into a self-ignoring folder of the task', async () => {
        const cwd = mkdtempSync(join(tmpdir(), 'uploads-'));
        const paths = await storeUploads(cwd, [{ name: 'Umsatz 2026.xlsx', data: Buffer.from('xlsx').toString('base64') }]);

        expect(paths).toEqual(['.argos/uploads/Umsatz 2026.xlsx']);
        expect(readFileSync(join(cwd, paths[0]!), 'utf8')).toBe('xlsx');
        expect(readFileSync(join(cwd, '.argos/uploads/.gitignore'), 'utf8')).toBe('*\n');
        expect(uploadNote(paths)).toContain('- .argos/uploads/Umsatz 2026.xlsx');
    });

    it('keeps names inside the folder', () => {
        expect(safeName('../../etc/passwd')).toBe('passwd');
        expect(safeName('C:\\Users\\ada\\report.csv')).toBe('report.csv');
        expect(safeName('.env')).toBe('env');
        expect(safeName('a;b|c.pdf')).toBe('a_b_c.pdf');
        expect(safeName('..')).toBe('upload');
    });
});
