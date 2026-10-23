import { isAbsolute, relative, resolve } from 'node:path';

/**
 * Guardrail for a task's session: Claude's file tools stay inside the task's
 * worktree. A path outside it needs a human's approval (`ask`), or — in an
 * automatic task nobody watches — is refused (`deny`). Not a security
 * boundary (Bash can still reach anything); it keeps tasks from editing each
 * other's files by mistake.
 */
export type GuardMode = 'ask' | 'deny';

/** Tool input keys that carry a file or search path. */
const PATH_KEYS: Record<string, string[]> = {
    Read: ['file_path'],
    Write: ['file_path'],
    Edit: ['file_path'],
    MultiEdit: ['file_path'],
    NotebookEdit: ['notebook_path'],
    Glob: ['path'],
    Grep: ['path'],
};

/** Paths a task may always touch besides its worktree. */
const ALWAYS_ALLOWED = ['/tmp'];

/** The first path of the tool input that lies outside `root`, or null. */
export function outsidePath(toolName: string, input: unknown, root: string): string | null {
    const keys = PATH_KEYS[toolName];
    if (keys === undefined || input === null || typeof input !== 'object') {
        return null;
    }
    for (const key of keys) {
        const value = (input as Record<string, unknown>)[key];
        if (typeof value !== 'string' || value === '') {
            continue;
        }
        const absolute = resolve(root, value);
        if (!within(absolute, root) && !ALWAYS_ALLOWED.some((allowed) => within(absolute, allowed))) {
            return absolute;
        }
    }

    return null;
}

function within(path: string, root: string): boolean {
    const rel = relative(root, path);

    return rel === '' || (!rel.startsWith('..') && !isAbsolute(rel));
}

/** A PreToolUse hook answer for the SDK. */
export function guardDecision(toolName: string, input: unknown, root: string, mode: GuardMode): { decision: GuardMode; reason: string } | null {
    const path = outsidePath(toolName, input, root);
    if (path === null) {
        return null;
    }

    return {
        decision: mode,
        reason: `${path} is outside this task's folder (${root}). Work in the task's own folder; other tasks of the workspace have their own.`,
    };
}
