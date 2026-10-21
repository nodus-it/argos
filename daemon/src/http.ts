import { timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import Fastify, { type FastifyInstance, type FastifyReply, type FastifyRequest } from 'fastify';
import { z } from 'zod';
import { ApprovalRegistry } from './approvals.js';
import { CodedError } from './errors.js';
import { ID_PATTERN } from './ids.js';
import type { Outbox } from './outbox.js';
import { ThreadSession, USER_PERMISSION_MODES, type QueryFactory, type SessionEnvironment } from './threadSession.js';
import { initWorkspace } from './workspaceInit.js';
import * as gitOps from './gitOps.js';
import { FileIndex, readWorkspaceFile } from './files.js';
import { runOneshot, type OneshotRunner } from './oneshot.js';
import { GATE_NAME_PATTERN, GateRunner, type GateScript } from './gates.js';
import { CheckpointStore, parseRef, type Checkpoint } from './checkpoints.js';

/** The daemon's version, from package.json (one directory above src/ and dist/). */
export const DAEMON_VERSION = (JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string }).version;

export interface AppDependencies {
    daemonToken: string;
    outbox: Outbox;
    queryFactory: QueryFactory;
    environment: SessionEnvironment;
    credentialHelper: string;
    oneshotRunner?: OneshotRunner;
    gateScript?: GateScript;
    versions?: { sdk?: string; claude?: string };
}

const permissionMode = z.enum(USER_PERMISSION_MODES);

/** Thread, agent and run ids. */
const idSchema = z.string().regex(ID_PATTERN);

/** Checkpoint refs; the shape is checked by `parseRef`. */
const checkpointRefSchema = z.string().max(200);

const startSchema = z.object({
    agent_id: idSchema,
    oauth_token: z.string().min(1),
    model: z.string().min(1),
    permission_mode: permissionMode,
    resume_session_id: z.string().min(1).nullish(),
    seq_offset: z.number().int().min(0).default(0),
    append_system_prompt: z.string().default(''),
});

const messageSchema = z.object({
    client_msg_id: z.string().min(1).max(64),
    author: z.object({ id: z.union([z.string(), z.number()]), name: z.string().min(1).max(120) }),
    text: z.string().max(200_000),
    images: z
        .array(z.object({ media_type: z.enum(['image/png', 'image/jpeg', 'image/gif', 'image/webp']), data: z.string().min(1) }))
        .max(5)
        .optional(),
});

const approvalSchema = z.object({
    decision: z.enum(['allow', 'deny']),
    updated_input: z.record(z.string(), z.unknown()).optional(),
    message: z.string().max(10_000).optional(),
    remember: z.boolean().optional(),
});

const initSchema = z.object({
    repo_url: z.string().min(1),
    base_branch: z.string().min(1),
    branch: z.string().min(1),
    git_user_name: z.string().min(1),
    git_user_email: z.string().min(1),
});

/** A repo-relative path for git pathspecs (no options, no traversal). */
const pathSchema = z.string().min(1).max(1000).refine((p) => !p.startsWith('-') && !p.startsWith('/') && !p.split('/').includes('..'), 'invalid path');

const refSchema = z.string().min(1).max(255).regex(/^[A-Za-z0-9._\/-]+$/).refine((ref) => !ref.startsWith('-') && !ref.includes('..'), 'invalid ref');

const commitSchema = z.object({
    subject: z.string().min(1).max(200),
    body: z.string().max(10_000).default(''),
    author: z.object({ name: z.string().min(1).max(120), email: z.string().min(3).max(200) }),
});

const oneshotSchema = z.object({
    agent_id: idSchema,
    oauth_token: z.string().min(1),
    model: z.string().min(1),
    system_prompt: z.string().max(100_000),
    user_prompt: z.string().max(1_000_000),
    json_schema: z.record(z.string(), z.unknown()).optional(),
});

/**
 * The daemon's command API (manager → daemon). Every route requires the
 * workspace's bearer token; the daemon is only reachable on the internal
 * `argos_ws` network.
 */
export function buildApp(deps: AppDependencies): { app: FastifyInstance; sessions: Map<string, ThreadSession> } {
    const app = Fastify({ logger: false, bodyLimit: 40 * 1024 * 1024 });
    const sessions = new Map<string, ThreadSession>();
    const approvals = new ApprovalRegistry();
    const gates = deps.gateScript === undefined ? null : new GateRunner(deps.gateScript, (event) => deps.outbox.push(null, event), deps.environment.workspaceDir);
    const checkpoints = new CheckpointStore(deps.environment.workspaceDir);
    const announceCheckpoint = (threadId: string, checkpoint: Checkpoint, clientMsgId: string | null): void => {
        deps.outbox.push(threadId, {
            type: 'checkpoint.created',
            data: { ref: checkpoint.ref, n: checkpoint.n, sha: checkpoint.sha, head: checkpoint.head, label: checkpoint.label, client_msg_id: clientMsgId },
        });
    };
    const gateRunner = (): GateRunner => {
        if (gates === null) {
            throw new CodedError(501, 'gates_unavailable', 'Quality gates are not configured');
        }

        return gates;
    };
    const expected = Buffer.from(`Bearer ${deps.daemonToken}`);

    app.addHook('onRequest', async (request, reply) => {
        const provided = Buffer.from(request.headers.authorization ?? '');
        if (provided.length !== expected.length || !timingSafeEqual(provided, expected)) {
            await reply.code(401).send({ error: { code: 'unauthorized', message: 'Missing or invalid bearer token' } });
        }
    });

    app.setErrorHandler(async (error, _request, reply) => {
        if (error instanceof CodedError) {
            return reply.code(error.status).send({ error: { code: error.code, message: error.message } });
        }
        if (error instanceof z.ZodError) {
            return reply.code(422).send({ error: { code: 'validation_failed', message: z.prettifyError(error) } });
        }
        const status = typeof (error as { statusCode?: unknown }).statusCode === 'number' ? (error as { statusCode: number }).statusCode : 500;

        return reply.code(status).send({ error: { code: 'internal_error', message: error instanceof Error ? error.message : 'Internal error' } });
    });

    const sessionFor = (request: FastifyRequest): ThreadSession => {
        const id = idSchema.parse((request.params as { id?: string }).id);
        const session = sessions.get(id);
        if (session === undefined) {
            throw new CodedError(404, 'unknown_thread', `Thread ${id} is not running`);
        }

        return session;
    };

    app.get('/health', async () => ({
        version: DAEMON_VERSION,
        sdk_version: deps.versions?.sdk ?? null,
        claude_version: deps.versions?.claude ?? null,
        threads: [...sessions.values()].map((s) => ({ id: s.threadId, state: s.currentState, claude_session_id: s.sessionId })),
    }));

    app.post('/workspace/init', async (request) => {
        const body = initSchema.parse(request.body);
        const status = await initWorkspace(
            deps.environment.workspaceDir,
            { repoUrl: body.repo_url, baseBranch: body.base_branch, branch: body.branch, gitUserName: body.git_user_name, gitUserEmail: body.git_user_email },
            deps.credentialHelper,
        );
        // Fresh base checkout: record pre-existing test/type failures so the
        // gates only report new ones. Runs in the background.
        if (status === 'ready' && gates !== null) {
            void gates.startBaseline();
        }

        return { status };
    });

    app.post('/threads/:id/start', async (request) => {
        const id = idSchema.parse((request.params as { id?: string }).id);
        const body = startSchema.parse(request.body);

        const existing = sessions.get(id);
        if (existing !== undefined && existing.currentState !== 'closed') {
            return { state: existing.currentState };
        }

        deps.outbox.setSeqOffset(id, body.seq_offset);
        const session = new ThreadSession(
            id,
            {
                agentId: body.agent_id,
                oauthToken: body.oauth_token,
                model: body.model,
                permissionMode: body.permission_mode,
                resumeSessionId: body.resume_session_id ?? null,
                appendSystemPrompt: body.append_system_prompt,
            },
            (event) => deps.outbox.push(id, event),
            approvals,
            deps.queryFactory,
            deps.environment,
            async (clientMsgId) => announceCheckpoint(id, await checkpoints.create(id, ''), clientMsgId),
        );
        sessions.set(id, session);
        session.start();

        return { state: session.currentState };
    });

    app.post('/threads/:id/messages', async (request, reply: FastifyReply) => {
        const session = sessionFor(request);
        const body = messageSchema.parse(request.body);
        if (session.currentState === 'closed') {
            throw new CodedError(409, 'thread_closed', 'Thread session is closed; start it again');
        }
        const accepted = session.send({ clientMsgId: body.client_msg_id, author: body.author, text: body.text, images: body.images });

        return reply.code(202).send({ queued: accepted });
    });

    app.post('/threads/:id/interrupt', async (request, reply) => {
        await sessionFor(request).interrupt();

        return reply.code(202).send({});
    });

    app.post('/threads/:id/approvals/:approvalId', async (request, reply) => {
        const session = sessionFor(request);
        const approvalId = z.string().min(1).parse((request.params as { approvalId?: string }).approvalId);
        const body = approvalSchema.parse(request.body);
        approvals.resolve(session.threadId, approvalId, { decision: body.decision, updatedInput: body.updated_input, message: body.message, remember: body.remember });

        return reply.code(202).send({});
    });

    app.post('/threads/:id/permission-mode', async (request, reply) => {
        const session = sessionFor(request);
        const { mode } = z.object({ mode: permissionMode }).parse(request.body);
        await session.setPermissionMode(mode);

        return reply.code(202).send({});
    });

    app.post('/threads/:id/model', async (request, reply) => {
        const session = sessionFor(request);
        const { model } = z.object({ model: z.string().min(1) }).parse(request.body);
        await session.setModel(model);

        return reply.code(202).send({});
    });

    app.post('/threads/:id/stop', async (request, reply) => {
        const session = sessionFor(request);
        await session.stop();
        sessions.delete(session.threadId);

        return reply.code(202).send({});
    });

    const dir = deps.environment.workspaceDir;
    const fileIndex = new FileIndex(dir);

    app.get('/files', async (request) => {
        const query = z.object({ q: z.string().max(200).default(''), limit: z.coerce.number().int().min(1).max(50).default(20) }).parse(request.query);

        return { files: await fileIndex.search(query.q, query.limit) };
    });

    app.get('/files/content', async (request) => {
        const query = z.object({ path: z.string().min(1).max(1000) }).parse(request.query);

        return readWorkspaceFile(dir, query.path);
    });

    app.get('/git/status', async () => gitOps.status(dir));

    app.get('/git/diff', async (request) => {
        const query = request.query as { base?: string; path?: string };
        const base = refSchema.parse(query.base);
        const path = query.path === undefined ? null : pathSchema.parse(query.path);

        return gitOps.diffAgainst(dir, base, 512 * 1024, path);
    });

    app.get('/git/changes', async (request) => {
        const base = refSchema.parse((request.query as { base?: string }).base);

        return gitOps.changedFiles(dir, base);
    });

    app.post('/git/fetch', async (_request, reply) => {
        await gitOps.fetch(dir);

        return reply.code(202).send({});
    });

    app.post('/git/commit', async (request, reply) => {
        const body = commitSchema.parse(request.body);
        const result = await gitOps.commit(dir, body.subject, body.body, body.author);
        if (result === null) {
            throw new CodedError(409, 'no_changes', 'Nothing to commit');
        }

        return reply.code(201).send(result);
    });

    app.post('/git/push', async (request, reply) => {
        const { branch } = z.object({ branch: refSchema }).parse(request.body);
        await gitOps.push(dir, branch);

        return reply.code(202).send({});
    });

    app.post('/checkpoints', async (request, reply) => {
        const body = z.object({ thread_id: idSchema, label: z.string().max(120).default('') }).parse(request.body);
        const checkpoint = await checkpoints.create(body.thread_id, body.label);
        announceCheckpoint(body.thread_id, checkpoint, null);

        return reply.code(201).send(checkpoint);
    });

    app.get('/checkpoints/diff', async (request) => {
        const { ref } = z.object({ ref: checkpointRefSchema }).parse(request.query);

        return checkpoints.diff(ref);
    });

    app.post('/checkpoints/restore', async (request) => {
        const { ref } = z.object({ ref: checkpointRefSchema }).parse(request.body);
        const { threadId } = parseRef(ref);
        // A restore rewrites the whole working tree, so no thread may be working.
        const busy = [...sessions.values()].find((s) => s.currentState === 'running' || s.currentState === 'starting' || s.currentState === 'waiting_input');
        if (busy !== undefined) {
            throw new CodedError(409, 'thread_running', busy.threadId === threadId ? 'Claude is working in this thread; interrupt it first' : `Claude is working in thread ${busy.threadId}; interrupt it first`);
        }
        const result = await checkpoints.restore(ref);
        announceCheckpoint(threadId, result.before, null);

        return result;
    });

    app.get('/gates', async () => ({ running: gateRunner().running, baseline: gateRunner().baseline }));

    app.post('/gates/run', async (request, reply) => {
        const { run_id: runId } = z.object({ run_id: idSchema }).parse(request.body);
        void gateRunner().start(runId);

        return reply.code(202).send({ run_id: runId });
    });

    app.get('/gates/fix-prompt', async (request) => {
        const query = z.object({ gate: z.string().regex(GATE_NAME_PATTERN), run_id: idSchema }).parse(request.query);

        return { prompt: await gateRunner().fixPrompt(query.gate, query.run_id) };
    });

    app.post('/oneshot', async (request) => {
        const body = oneshotSchema.parse(request.body);
        if (deps.oneshotRunner === undefined) {
            throw new CodedError(501, 'oneshot_unavailable', 'One-shot prompts are not configured');
        }

        return runOneshot(
            deps.oneshotRunner,
            { agentId: body.agent_id, oauthToken: body.oauth_token, model: body.model, systemPrompt: body.system_prompt, userPrompt: body.user_prompt, jsonSchema: body.json_schema },
            deps.environment,
        );
    });

    return { app, sessions };
}
