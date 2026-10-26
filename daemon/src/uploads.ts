import { mkdir, writeFile } from 'node:fs/promises';
import { basename, join } from 'node:path';

/** Where files attached to a message land, relative to the task folder. */
export const UPLOAD_DIR = '.argos/uploads';

export interface MessageFile {
    name: string;
    data: string;
}

/**
 * Write attached files into the task folder and return their relative
 * paths. The folder ignores itself, so the files never end up in a commit.
 */
export async function storeUploads(cwd: string, files: MessageFile[]): Promise<string[]> {
    const dir = join(cwd, UPLOAD_DIR);
    await mkdir(dir, { recursive: true });
    await writeFile(join(dir, '.gitignore'), '*\n');

    const paths: string[] = [];
    for (const file of files) {
        const name = safeName(file.name);
        await writeFile(join(dir, name), Buffer.from(file.data, 'base64'));
        paths.push(`${UPLOAD_DIR}/${name}`);
    }

    return paths;
}

/** The base name with anything outside a conservative set replaced; never hidden. */
export function safeName(name: string): string {
    const cleaned = basename(name.replaceAll('\\', '/')).replace(/[^\w.\- ]+/gu, '_').replace(/^\.+/, '');

    return cleaned === '' ? 'upload' : cleaned.slice(0, 120);
}

/** The note appended to the message so Claude knows where the files are. */
export function uploadNote(paths: string[]): string {
    return `\n\nAttached files (in this task's folder, not committed):\n${paths.map((p) => `- ${p}`).join('\n')}`;
}
