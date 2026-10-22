import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { Options } from '@anthropic-ai/claude-agent-sdk';
import { listModels, ModelsError } from '../src/models.js';
import type { QueryFactory, QueryLike } from '../src/taskSession.js';

describe('listModels', () => {
    let dir: string;
    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-models-'));
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const env = () => ({ workspaceDir: dir, agentsDir: join(dir, 'agents'), baseEnv: { PATH: '/usr/bin', ANTHROPIC_API_KEY: 'k' } });

    const factoryWith = (supportedModels: QueryLike['supportedModels'], seen: { options?: Options; prompted: boolean; closed: boolean }): QueryFactory =>
        ({ prompt, options }) => {
            seen.options = options;
            // Nothing may ever be sent: pulling from the prompt must not yield before close.
            void (async () => {
                const next = await prompt[Symbol.asyncIterator]().next();
                seen.prompted = next.done !== true;
            })();

            return {
                [Symbol.asyncIterator]: async function* () {},
                interrupt: async () => undefined,
                setPermissionMode: async () => undefined,
                setModel: async () => undefined,
                supportedCommands: async () => [],
                supportedModels,
                close: () => {
                    seen.closed = true;
                },
            } as unknown as QueryLike;
        };

    it('asks Claude Code for the models without sending a message', async () => {
        const seen = { prompted: false, closed: false } as { options?: Options; prompted: boolean; closed: boolean };
        const models = await listModels(
            factoryWith(async () => [{ value: 'opus', displayName: 'Opus', description: 'Opus 5.5 · Complex tasks', resolvedModel: 'claude-opus-5-5' }], seen),
            { agentId: 'a1', oauthToken: 'oat' },
            env(),
        );

        expect(models).toEqual([{ value: 'opus', display_name: 'Opus', description: 'Opus 5.5 · Complex tasks', resolved_model: 'claude-opus-5-5' }]);
        expect(seen.options?.env?.CLAUDE_CODE_OAUTH_TOKEN).toBe('oat');
        expect(seen.options?.env?.ANTHROPIC_API_KEY).toBeUndefined();
        expect(seen.options?.tools).toEqual([]);
        expect(seen.closed).toBe(true);
        expect(seen.prompted).toBe(false);
    });

    it('gives up when Claude Code does not answer', async () => {
        const seen = { prompted: false, closed: false } as { options?: Options; prompted: boolean; closed: boolean };
        await expect(listModels(factoryWith(() => new Promise(() => undefined), seen), { agentId: 'a1', oauthToken: 'oat' }, env(), 20)).rejects.toBeInstanceOf(ModelsError);
        expect(seen.closed).toBe(true);
    });
});
