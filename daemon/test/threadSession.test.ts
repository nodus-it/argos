import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ApprovalRegistry } from '../src/approvals.js';
import type { PendingEvent } from '../src/events.js';
import { ThreadSession, type ThreadStartParams } from '../src/threadSession.js';
import { assistantText, fakeFactory, streamDelta, textOfUserMessage, waitFor, type Script } from './fakeQuery.js';

const params: ThreadStartParams = {
    agentId: 'agent_1',
    oauthToken: 'sk-ant-oat-secret',
    model: 'claude-sonnet-5-5',
    permissionMode: 'default',
    appendSystemPrompt: 'Several humans may write in this thread.',
};

const echo: Script = async function* (message) {
    yield* streamDelta('msg_1', 'Hel');
    yield assistantText('msg_1', `echo: ${textOfUserMessage(message)}`);
};

describe('ThreadSession', () => {
    let dir: string;
    let events: PendingEvent[];

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'argos-daemon-'));
        events = [];
    });
    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const make = (script: Script, overrides: Partial<ThreadStartParams> = {}, configure?: Parameters<typeof fakeFactory>[1]) => {
        const { factory, queries } = fakeFactory(script, configure);
        const approvals = new ApprovalRegistry();
        const session = new ThreadSession('thread_1', { ...params, ...overrides }, (e) => events.push(e), approvals, factory, {
            workspaceDir: dir,
            agentsDir: join(dir, 'agents'),
            baseEnv: { PATH: '/usr/bin', ARGOS_DAEMON_TOKEN: 'daemon-secret', ANTHROPIC_API_KEY: 'sk-api', HOME: '/home/agent' },
        });

        return { session, queries, approvals };
    };

    const types = () => events.map((e) => e.type);
    const ofType = (type: string) => events.filter((e) => e.type === type);

    it('starts a streaming session with subscription auth and an isolated config dir', async () => {
        const { session, queries } = make(echo);
        session.start();
        await waitFor(() => ofType('session.started').length === 1);

        const options = queries[0]!.options;
        expect(options.env?.CLAUDE_CODE_OAUTH_TOKEN).toBe('sk-ant-oat-secret');
        expect(options.env?.CLAUDE_CONFIG_DIR).toBe(join(dir, 'agents', 'agent_1'));
        expect(options.env?.ANTHROPIC_API_KEY).toBeUndefined();
        expect(options.env?.ARGOS_DAEMON_TOKEN).toBeUndefined();
        expect(options.includePartialMessages).toBe(true);
        expect(options.permissionMode).toBe('default');
        expect(options.settingSources).toEqual(['project', 'local']);
        expect(options.systemPrompt).toEqual({ type: 'preset', preset: 'claude_code', append: 'Several humans may write in this thread.' });
        expect(options.resume).toBeUndefined();

        expect(ofType('session.started')[0]!.data).toMatchObject({
            claude_session_id: 'session-1',
            commands: [{ name: 'review', description: 'Review the diff', argument_hint: '[path]' }],
            models: [
                { value: 'default', display_name: 'Default (recommended)', description: 'Sonnet 5.5', resolved_model: 'claude-sonnet-5-5' },
                { value: 'opus', display_name: 'Opus', description: 'Most capable', resolved_model: 'claude-opus-5-5' },
            ],
        });
        expect(session.currentState).toBe('idle');
        await session.stop();
    });

    it('prefixes the author, reports acceptance, streams deltas and completes the turn', async () => {
        const { session } = make(echo);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 7, name: 'Bastian' }, text: 'Hi' });

        await waitFor(() => ofType('turn.completed').length === 1);
        expect(ofType('user.accepted')[0]!.data).toEqual({ client_msg_id: 'm1' });
        expect(ofType('assistant.delta')[0]!.data).toEqual({ message_id: 'msg_1', text: 'Hel' });
        expect(ofType('assistant.message')[0]!.data).toEqual({ message_id: 'msg_1', text: 'echo: [Bastian]: Hi' });
        expect(ofType('turn.completed')[0]!.data).toMatchObject({ session_cost_usd: 0.0123, is_error: false });
        expect(session.currentState).toBe('idle');
        await session.stop();
    });

    it('ignores duplicate client message ids', async () => {
        const { session } = make(echo);
        session.start();
        expect(session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'one' })).toBe(true);
        expect(session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'one' })).toBe(false);
        await waitFor(() => ofType('turn.completed').length === 1);
        await new Promise((r) => setTimeout(r, 20));
        expect(ofType('turn.completed')).toHaveLength(1);
        await session.stop();
    });

    it('accepts a follow-up while a turn is running and processes it next', async () => {
        let release: () => void = () => {};
        const gate = new Promise<void>((r) => (release = r));
        const slow: Script = async function* (message) {
            const text = textOfUserMessage(message);
            if (text.includes('first')) {
                await gate;
            }
            yield assistantText(`msg_${text.length}`, `done ${text}`);
        };
        const { session } = make(slow);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'first' });
        await waitFor(() => session.currentState === 'running');
        session.send({ clientMsgId: 'm2', author: { id: 2, name: 'B' }, text: 'second' });

        // Not accepted yet: the SDK has not pulled it while the first turn runs.
        expect(ofType('user.accepted').map((e) => e.data.client_msg_id)).toEqual(['m1']);
        release();
        await waitFor(() => ofType('turn.completed').length === 2);
        expect(ofType('user.accepted').map((e) => e.data.client_msg_id)).toEqual(['m1', 'm2']);
        expect(ofType('assistant.message').map((e) => e.data.text)).toEqual(['done [A]: first', 'done [B]: second']);
        await session.stop();
    });

    it('relays permission requests and applies an allow decision', async () => {
        const script: Script = async function* (_message, ctx) {
            const decision = await ctx.askPermission('Edit', { file_path: 'a.php' }, 'toolu_9');
            yield assistantText('msg_2', decision.behavior === 'allow' ? `edited ${JSON.stringify(decision.updatedInput)}` : 'skipped');
        };
        const { session, approvals } = make(script);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'edit' });

        await waitFor(() => ofType('approval.requested').length === 1);
        const request = ofType('approval.requested')[0]!.data;
        expect(request).toMatchObject({ kind: 'tool', tool_name: 'Edit', tool_use_id: 'toolu_9', input: { file_path: 'a.php' } });
        expect(session.currentState).toBe('waiting_input');

        approvals.resolve('thread_1', request.approval_id as string, { decision: 'allow' });
        await waitFor(() => ofType('turn.completed').length === 1);
        expect(ofType('assistant.message')[0]!.data.text).toBe('edited {"file_path":"a.php"}');
        await session.stop();
    });

    it('classifies plan and question approvals and passes updated input through', async () => {
        const script: Script = async function* (_message, ctx) {
            const answer = await ctx.askPermission('AskUserQuestion', { questions: [{ question: 'Which?' }] }, 'toolu_q');
            yield assistantText('msg_3', JSON.stringify(answer));
        };
        const { session, approvals } = make(script);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'ask' });
        await waitFor(() => ofType('approval.requested').length === 1);
        const request = ofType('approval.requested')[0]!.data;
        expect(request.kind).toBe('question');
        expect(ApprovalRegistry.kindFor('ExitPlanMode')).toBe('plan');

        approvals.resolve('thread_1', request.approval_id as string, { decision: 'allow', updatedInput: { questions: [], answers: { 'Which?': 'B' } } });
        await waitFor(() => ofType('turn.completed').length === 1);
        expect(JSON.parse(ofType('assistant.message')[0]!.data.text as string)).toMatchObject({
            behavior: 'allow',
            updatedInput: { answers: { 'Which?': 'B' } },
        });
        await session.stop();
    });

    it('denies with the given message', async () => {
        const script: Script = async function* (_message, ctx) {
            const decision = await ctx.askPermission('Bash', { command: 'rm -rf /' });
            yield assistantText('m', decision.behavior === 'deny' ? decision.message : 'ran');
        };
        const { session, approvals } = make(script);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'go' });
        await waitFor(() => ofType('approval.requested').length === 1);
        approvals.resolve('thread_1', ofType('approval.requested')[0]!.data.approval_id as string, { decision: 'deny', message: 'Nope' });
        await waitFor(() => ofType('assistant.message').length === 1);
        expect(ofType('assistant.message')[0]!.data.text).toBe('Nope');
        await session.stop();
    });

    it('interrupt cancels open approvals and keeps the session usable', async () => {
        const script: Script = async function* (message, ctx) {
            if (textOfUserMessage(message).includes('edit')) {
                const decision = await ctx.askPermission('Edit', { file_path: 'x' });
                yield assistantText('m1', `decision ${decision.behavior}`);

                return;
            }
            yield assistantText('m2', 'still here');
        };
        const { session, queries } = make(script);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'edit' });
        await waitFor(() => ofType('approval.requested').length === 1);

        await session.interrupt();
        expect(queries[0]!.interrupted).toBe(true);
        expect(ofType('approval.cancelled')[0]!.data).toMatchObject({ reason: 'interrupted' });

        await waitFor(() => ofType('turn.completed').length === 1);
        session.send({ clientMsgId: 'm2', author: { id: 1, name: 'A' }, text: 'hello' });
        await waitFor(() => ofType('assistant.message').some((e) => e.data.text === 'still here'));
        expect(queries).toHaveLength(1);
        await session.stop();
    });

    it('resumes the Claude session when the query ends because of an interrupt', async () => {
        const script: Script = async function* (message) {
            if (textOfUserMessage(message).includes('long')) {
                await new Promise((r) => setTimeout(r, 30));
            }
            yield assistantText('m', `reply ${textOfUserMessage(message)}`);
        };
        const { session, queries } = make(script, {}, (q) => {
            q.endAfterInterrupt = true;
        });
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'long' });
        await waitFor(() => session.currentState === 'running');
        await session.interrupt();

        await waitFor(() => queries.length === 2);
        expect(queries[1]!.options.resume).toBe('session-1');
        session.send({ clientMsgId: 'm2', author: { id: 1, name: 'A' }, text: 'after' });
        await waitFor(() => ofType('assistant.message').some((e) => e.data.text === 'reply [A]: after'));
        expect(ofType('error')).toHaveLength(0);
        await session.stop();
    });

    it('keeps a message queued during an interrupted turn for the resumed query', async () => {
        const script: Script = async function* (message) {
            if (textOfUserMessage(message).includes('long')) {
                await new Promise((r) => setTimeout(r, 30));
            }
            yield assistantText('m', `reply ${textOfUserMessage(message)}`);
        };
        const { session, queries } = make(script, {}, (q) => {
            q.endAfterInterrupt = true;
        });
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'long' });
        await waitFor(() => session.currentState === 'running');
        // Not taken yet: the SDK pulls the next message only after the turn.
        session.send({ clientMsgId: 'm2', author: { id: 1, name: 'A' }, text: 'queued' });
        await session.interrupt();

        await waitFor(() => queries.length === 2);
        await waitFor(() => ofType('assistant.message').some((e) => e.data.text === 'reply [A]: queued'));
        await session.stop();
    });

    it('reports only the failing query\'s stderr', async () => {
        const script: Script = async function* (message, ctx) {
            const text = textOfUserMessage(message);
            if (text.includes('long')) {
                ctx.options.stderr?.('stale noise from the first query\n');
                await new Promise((r) => setTimeout(r, 30));
            }
            if (text.includes('crash')) {
                ctx.options.stderr?.('fresh failure\n');
                throw new Error('boom');
            }
            yield assistantText('m', 'ok');
        };
        const { session, queries } = make(script, {}, (q) => {
            q.endAfterInterrupt = true;
        });
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'long' });
        await waitFor(() => session.currentState === 'running');
        await session.interrupt();
        await waitFor(() => queries.length === 2);
        session.send({ clientMsgId: 'm2', author: { id: 1, name: 'A' }, text: 'crash' });

        await waitFor(() => session.currentState === 'closed');
        const message = String(ofType('error')[0]!.data.message);
        expect(message).toContain('fresh failure');
        expect(message).not.toContain('stale noise');
    });

    it('passes resume and reports a crash as session_failed', async () => {
        const crashing: Script = async function* () {
            throw new Error("You've hit your limit · resets 9pm (UTC)");
        };
        const { session, queries } = make(crashing, { resumeSessionId: 'old-session' });
        session.start();
        expect(queries[0]!.options.resume).toBe('old-session');
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'go' });

        await waitFor(() => session.currentState === 'closed');
        expect(ofType('usage_limit')).toHaveLength(1);
        expect(ofType('error')[0]!.data.code).toBe('session_failed');
    });

    it('forwards permission mode and model changes', async () => {
        const { session, queries } = make(echo);
        session.start();
        await waitFor(() => ofType('session.started').length === 1);
        await session.setPermissionMode('acceptEdits');
        await session.setModel('claude-opus-5-5');
        expect(queries[0]!.permissionModes).toEqual(['acceptEdits']);
        expect(queries[0]!.models).toEqual(['claude-opus-5-5']);
        await session.stop();
    });

    it('builds image content blocks', async () => {
        const seen: unknown[] = [];
        const script: Script = async function* (message) {
            seen.push(message.message.content);
            yield assistantText('m', 'ok');
        };
        const { session } = make(script);
        session.start();
        session.send({ clientMsgId: 'm1', author: { id: 1, name: 'A' }, text: 'look', images: [{ media_type: 'image/png', data: 'iVBOR' }] });
        await waitFor(() => seen.length === 1);
        expect(seen[0]).toEqual([
            { type: 'text', text: '[A]: look' },
            { type: 'image', source: { type: 'base64', media_type: 'image/png', data: 'iVBOR' } },
        ]);
        await session.stop();
    });

    it('stop closes the query and reports closed', async () => {
        const { session, queries } = make(echo);
        session.start();
        await waitFor(() => ofType('session.started').length === 1);
        await session.stop();
        expect(queries[0]!.closed).toBe(true);
        expect(types().at(-1)).toBe('status');
        expect(session.currentState).toBe('closed');
        expect(ofType('error')).toHaveLength(0);
    });
});
