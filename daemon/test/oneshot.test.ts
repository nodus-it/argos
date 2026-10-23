import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { Options } from '@anthropic-ai/claude-agent-sdk';
import type { SdkMessageLike } from '../src/normalize.js';
import { OneshotError, runOneshot, type OneshotRunner } from '../src/oneshot.js';

describe('runOneshot', () => {
    let dir: string;
    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-oneshot-'));
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const runnerReturning = (messages: SdkMessageLike[], seen: Options[] = []): OneshotRunner =>
        ({ options }) => {
            seen.push(options);

            return (async function* () {
                yield* messages;
            })();
        };
    const params = { agentId: 'a1', oauthToken: 'oat', model: 'claude-haiku-4-5-20251001', systemPrompt: 'sys', userPrompt: 'diff…' };
    const env = () => ({ workspaceDir: dir, agentsDir: join(dir, 'agents'), baseEnv: { PATH: '/usr/bin', ANTHROPIC_API_KEY: 'k', ARGOS_DAEMON_TOKEN: 'd' } });

    it('runs without tools or project settings on the agent subscription', async () => {
        const seen: Options[] = [];
        const result = await runOneshot(
            runnerReturning([{ type: 'result', subtype: 'success', is_error: false, result: '{}', structured_output: { subject: 'feat: x', body: '' }, total_cost_usd: 0.001 }], seen),
            { ...params, jsonSchema: { type: 'object' } },
            env(),
        );

        expect(result).toEqual({ text: '{}', structured: { subject: 'feat: x', body: '' }, cost_usd: 0.001 });
        expect(seen[0]?.tools).toEqual([]);
        expect(seen[0]?.settingSources).toEqual([]);
        expect(seen[0]?.outputFormat).toEqual({ type: 'json_schema', schema: { type: 'object' } });
        expect(seen[0]?.env?.CLAUDE_CODE_OAUTH_TOKEN).toBe('oat');
        expect(seen[0]?.env?.ANTHROPIC_API_KEY).toBeUndefined();
        expect(seen[0]?.env?.ARGOS_DAEMON_TOKEN).toBeUndefined();
    });

    it('falls back to JSON in the text', async () => {
        const result = await runOneshot(
            runnerReturning([{ type: 'result', subtype: 'success', is_error: false, result: '```json\n{"subject":"fix: y","body":"b"}\n```' }]),
            { ...params, jsonSchema: { type: 'object' } },
            env(),
        );

        expect(result.structured).toEqual({ subject: 'fix: y', body: 'b' });
    });

    it('flags a refused subscription token so the manager can mark the agent', async () => {
        const throwing: OneshotRunner = () =>
            (async function* () {
                throw new Error('API Error: 401 authentication_error: OAuth token has expired');
            })();

        await expect(runOneshot(throwing, params, env())).rejects.toMatchObject({ code: 'agent_auth_failed' });
        await expect(
            runOneshot(runnerReturning([{ type: 'result', subtype: 'error_during_execution', is_error: true, errors: ['Invalid API key · Please run /login'] }]), params, env()),
        ).rejects.toMatchObject({ code: 'agent_auth_failed' });
    });

    it('raises on error results or no result', async () => {
        await expect(runOneshot(runnerReturning([{ type: 'result', subtype: 'error_max_turns', is_error: true, errors: ['too long'] }]), params, env())).rejects.toBeInstanceOf(OneshotError);
        await expect(runOneshot(runnerReturning([]), params, env())).rejects.toThrow('no result');
    });
});
