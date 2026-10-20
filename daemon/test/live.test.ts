/**
 * Opt-in live test against the real `claude` binary on a Claude subscription.
 *
 *   ARGOS_LIVE_CLAUDE_TOKEN  token from `claude setup-token`
 *   ARGOS_LIVE_REPO_DIR      a local git checkout the test may write to
 *   ARGOS_LIVE_MODEL         optional, defaults to claude-haiku-5-5
 *   ARGOS_CLAUDE_BIN         optional path to the claude binary
 *
 * Without the two required variables the suite is skipped. This is the
 * go/no-go check for subscription auth in SDK streaming mode.
 */
import { query } from '@anthropic-ai/claude-agent-sdk';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { ApprovalRegistry } from '../src/approvals.js';
import type { PendingEvent } from '../src/events.js';
import { ThreadSession, type QueryFactory, type QueryLike } from '../src/threadSession.js';
import { waitFor } from './fakeQuery.js';

const token = process.env.ARGOS_LIVE_CLAUDE_TOKEN;
const repoDir = process.env.ARGOS_LIVE_REPO_DIR;
const enabled = token !== undefined && token !== '' && repoDir !== undefined && repoDir !== '';
const model = process.env.ARGOS_LIVE_MODEL ?? 'claude-haiku-5-5';
const TURN_TIMEOUT = 180_000;

describe.skipIf(!enabled)('live: Claude subscription in SDK streaming mode', () => {
    let agentsDir: string;
    const approvals = new ApprovalRegistry();
    const factory: QueryFactory = (params) => query(params) as unknown as QueryLike;

    beforeAll(() => {
        agentsDir = mkdtempSync(join(tmpdir(), 'argos-live-agents-'));
    });
    afterAll(() => rmSync(agentsDir, { recursive: true, force: true }));

    const make = (events: PendingEvent[], permissionMode: 'default' | 'acceptEdits', resumeSessionId?: string) =>
        new ThreadSession('live', { agentId: 'live', oauthToken: token!, model, permissionMode, resumeSessionId: resumeSessionId ?? null }, (e) => events.push(e), approvals, factory, {
            workspaceDir: repoDir!,
            agentsDir,
            claudeBin: process.env.ARGOS_CLAUDE_BIN,
        });
    const completed = (events: PendingEvent[]) => events.filter((e) => e.type === 'turn.completed').length;
    const texts = (events: PendingEvent[]) => events.filter((e) => e.type === 'assistant.message').map((e) => String(e.data.text));

    it(
        'streams, accepts follow-ups, interrupts, gates edits and resumes',
        async () => {
            const events: PendingEvent[] = [];
            const session = make(events, 'default');
            session.start();

            // 1. Simple streamed answer.
            session.send({ clientMsgId: 'l1', author: { id: 1, name: 'Tester' }, text: 'Remember the word "kumquat". Reply with just OK.' });
            await waitFor(() => completed(events) >= 1, TURN_TIMEOUT);
            expect(events.some((e) => e.type === 'session.started')).toBe(true);
            expect(events.some((e) => e.type === 'assistant.delta')).toBe(true);
            expect(events.some((e) => e.type === 'error')).toBe(false);

            // 2. Follow-up while a turn runs.
            session.send({ clientMsgId: 'l2', author: { id: 1, name: 'Tester' }, text: 'Count slowly from 1 to 30, one number per line.' });
            await waitFor(() => session.currentState === 'running', TURN_TIMEOUT);
            session.send({ clientMsgId: 'l3', author: { id: 2, name: 'Pair' }, text: 'Then say DONE.' });

            // 3. Interrupt, then the session keeps working.
            await session.interrupt();
            await waitFor(() => session.currentState === 'idle' || completed(events) >= 2, TURN_TIMEOUT);
            await waitFor(() => events.some((e) => e.type === 'user.accepted' && e.data.client_msg_id === 'l3'), TURN_TIMEOUT);
            await waitFor(() => session.currentState === 'idle', TURN_TIMEOUT);

            // 4. Edit approval: deny leaves the file untouched, allow writes it.
            const file = join(repoDir!, 'argos-live-test.txt');
            rmSync(file, { force: true });
            const before = events.length;
            session.send({ clientMsgId: 'l4', author: { id: 1, name: 'Tester' }, text: 'Create the file argos-live-test.txt containing exactly: hello' });
            await waitFor(() => events.slice(before).some((e) => e.type === 'approval.requested'), TURN_TIMEOUT);
            const denied = events.slice(before).find((e) => e.type === 'approval.requested')!;
            approvals.resolve('live', String(denied.data.approval_id), { decision: 'deny', message: 'Not now. Do not retry.' });
            await waitFor(() => session.currentState === 'idle', TURN_TIMEOUT);
            expect(existsSync(file)).toBe(false);

            const beforeAllow = events.length;
            session.send({ clientMsgId: 'l5', author: { id: 1, name: 'Tester' }, text: 'Now please create it after all, same content.' });
            await waitFor(() => events.slice(beforeAllow).some((e) => e.type === 'approval.requested'), TURN_TIMEOUT);
            const allowed = events.slice(beforeAllow).find((e) => e.type === 'approval.requested')!;
            approvals.resolve('live', String(allowed.data.approval_id), { decision: 'allow' });
            await waitFor(() => session.currentState === 'idle' && existsSync(file), TURN_TIMEOUT);
            expect(readFileSync(file, 'utf8').trim()).toBe('hello');
            rmSync(file, { force: true });

            // 5. Stop and resume keeps the context.
            const sessionId = session.sessionId;
            expect(sessionId).not.toBeNull();
            await session.stop();

            const resumedEvents: PendingEvent[] = [];
            const resumed = make(resumedEvents, 'default', sessionId!);
            resumed.start();
            resumed.send({ clientMsgId: 'l6', author: { id: 1, name: 'Tester' }, text: 'Which word did I ask you to remember? Answer with the word only.' });
            await waitFor(() => completed(resumedEvents) >= 1, TURN_TIMEOUT);
            expect(texts(resumedEvents).join(' ').toLowerCase()).toContain('kumquat');
            await resumed.stop();
        },
        TURN_TIMEOUT * 6,
    );
});
