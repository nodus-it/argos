import { existsSync, readFileSync } from 'node:fs';
import { randomUUID, timingSafeEqual } from 'node:crypto';
import Fastify, { type FastifyInstance, type FastifyReply, type FastifyRequest } from 'fastify';
import { z } from 'zod';
import { ApprovalRegistry } from './approvals.js';
import { CodedError } from './errors.js';
import { ID_PATTERN } from './ids.js';
import type { Outbox } from './outbox.js';
import { TaskSession, USER_PERMISSION_MODES, type QueryFactory, type SessionEnvironment } from './taskSession.js';
import { initWorkspace } from './workspaceInit.js';
import * as gitOps from './gitOps.js';
import { FileIndex, readWorkspaceFile } from './files.js';
import { listModels } from './models.js';
import { runOneshot, type OneshotRunner } from './oneshot.js';
import { GATE_NAME_PATTERN, GateRunner, tail, type GateScript } from './gates.js';
import { CheckpointStore, parseRef, type Checkpoint } from './checkpoints.js';
import { layoutFor, saveTaskEnv, SLUG_PATTERN, worktreeDir, type Layout } from './layout.js';
import { prepareWorktree, removeWorktree, SETUP_TIMEOUT_MS, writeEnv, type SetupScript } from './worktrees.js';
import { join } from 'node:path';

/** The daemon's version, from package.json (one directory above src/ and dist/). */
const DAEMON_VERSION = (JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string }).version;

export interface AppDependencies {
    daemonToken: string;
    outbox: Outbox;
    queryFactory: QueryFactory;
    environment: SessionEnvironment;
    credentialHelper: string;
    oneshotRunner?: OneshotRunner;
    gateScript?: GateScript;
    setupScript?: SetupScript;
    versions?: { sdk?: string; claude?: string };
}

const permissionMode = z.enum(USER_PERMISSION_MODES);

/** Task, agent and run ids. */
const idSchema = z.string().regex(ID_PATTERN);

/** Checkpoint refs; the shape is checked by `parseRef`. */
const checkpointRefSchema = z.string().max(200);

/** Task slugs (worktree folder names). */
const slugSchema = z.string().regex(SLUG_PATTERN);

const effortLevel = z.enum(['low', 'medium', 'high', 'xhigh', 'max']);

/** `.env` values of a task (names in upper case). */
const envSchema = z.record(z.string().regex(/^[A-Z][A-Z0-9_]*$/), z.string().max(4000)).default({});

/** Author and committer of commits. */
const authorSchema = z.object({ name: z.string().min(1).max(120), email: z.string().min(3).max(200) });

const startSchema = z.object({
    worktree: slugSchema,
    guard: z.enum(['ask', 'deny']).default('ask'),
    agent_id: idSchema,
    oauth_token: z.string().min(1),
    model: z.string().min(1),
    effort: effortLevel.nullish(),
    permission_mode: permissionMode,
    resume_session_id: z.string().min(1).nullish(),
    seq_offset: z.number().int().min(0).default(0),
    append_system_prompt: z.string().default(''),
    /** Who Claude's commits in this task are by (the task's owner). */
    git_author: authorSchema.nullish(),
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
    git_user_name: z.string().min(1),
    git_user_email: z.string().min(1),
});

/** A repo-relative path for git pathspecs (no options, no traversal). */
const pathSchema = z.string().min(1).max(1000).refine((p) => !p.startsWith('-') && !p.startsWith('/') && !p.split('/').includes('..'), 'invalid path');

const refSchema = z.string().min(1).max(255).regex(/^[A-Za-z0-9._\/-]+$/).refine((ref) => !ref.startsWith('-') && !ref.includes('..'), 'invalid ref');

const prepareSchema = z.object({
    task_id: idSchema,
    branch: refSchema,
    base_branch: refSchema,
    env: envSchema,
    seq_offset: z.number().int().min(0).default(0),
    /** Branch of a workspace setup that is not merged yet: its setup steps apply. */
    setup_ref: refSchema.optional(),
});

const setupCheckSchema = z.object({
    ref: refSchema,
    env: envSchema,
});

/** Worktree the setup check uses; never a task's. */
const SETUP_CHECK_SLUG = 'argos-setup-check';

const commitSchema = z.object({
    subject: z.string().min(1).max(200),
    body: z.string().max(10_000).default(''),
    author: authorSchema,
});

const modelsSchema = z.object({
    agent_id: idSchema,
    oauth_token: z.string().min(1),
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
export function buildApp(deps: AppDependencies): { app: FastifyInstance; sessions: Map<string, TaskSession> } {
    const app = Fastify({ logger: false, bodyLimit: 40 * 1024 * 1024 });
    const sessions = new Map<string, TaskSession>();
    const bootId = randomUUID();
    const sessionsIn = (dir: string): TaskSession[] => [...sessions.values()].filter((s) => s.cwd === dir);
    const approvals = new ApprovalRegistry();
    const layout: Layout = layoutFor(deps.environment.workspaceDir);
    const gates = deps.gateScript === undefined ? null : new GateRunner(deps.gateScript, (event) => deps.outbox.push(null, event), layout);
    const checkpointStores = new Map<string, CheckpointStore>();
    const fileIndexes = new Map<string, FileIndex>();
    const preparing = new Set<string>();

    const checkpointsFor = (dir: string): CheckpointStore => {
        let store = checkpointStores.get(dir);
        if (store === undefined) {
            store = new CheckpointStore(dir);
            checkpointStores.set(dir, store);
        }

        return store;
    };
    const filesFor = (dir: string): FileIndex => {
        let index = fileIndexes.get(dir);
        if (index === undefined) {
            index = new FileIndex(dir);
            fileIndexes.set(dir, index);
        }

        return index;
    };
    const announceCheckpoint = (taskId: string, checkpoint: Checkpoint, clientMsgId: string | null): void => {
        deps.outbox.push(taskId, {
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
    /** The worktree of the route's `:slug`; 404 until the task is prepared. */
    const worktreeOf = (request: FastifyRequest): string => {
        const dir = worktreeDir(layout, slugSchema.parse((request.params as { slug?: string }).slug));
        if (!existsSync(join(dir, '.git'))) {
            throw new CodedError(404, 'unknown_worktree', 'This task has no working copy yet');
        }

        return dir;
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

    const sessionFor = (request: FastifyRequest): TaskSession => {
        const id = idSchema.parse((request.params as { id?: string }).id);
        const session = sessions.get(id);
        if (session === undefined) {
            throw new CodedError(404, 'unknown_task', `Task ${id} is not running`);
        }

        return session;
    };

    // ── Workspace ────────────────────────────────────────────────────────

    app.get('/health', async () => ({
        version: DAEMON_VERSION,
        // A new id per daemon process: the manager notices a restart (lost sessions, cut preparations).
        boot_id: bootId,
        sdk_version: deps.versions?.sdk ?? null,
        claude_version: deps.versions?.claude ?? null,
        tasks: [...sessions.values()].map((s) => ({ id: s.taskId, state: s.currentState, claude_session_id: s.sessionId })),
    }));

    app.post('/workspace/init', async (request) => {
        const body = initSchema.parse(request.body);
        const status = await initWorkspace(
            layout.mainDir,
            { repoUrl: body.repo_url, baseBranch: body.base_branch, gitUserName: body.git_user_name, gitUserEmail: body.git_user_email },
            deps.credentialHelper,
        );
        // Record pre-existing test/type failures of the base so the gates
        // only report new ones. Runs in the background.
        if (gates !== null) {
            const head = (await gitOps.headCommit(layout.mainDir)) ?? '';
            void gates.ensureBaseline(head);
        }

        return { status };
    });

    app.post('/git/fetch', async (_request, reply) => {
        await gitOps.fetch(layout.mainDir);

        return reply.code(202).send({});
    });


    /** The models the agent's subscription offers (Claude Code's /model), without sending a message. */
    app.post('/models', async (request) => {
        const body = modelsSchema.parse(request.body);

        return { models: await listModels(deps.queryFactory, { agentId: body.agent_id, oauthToken: body.oauth_token }, deps.environment) };
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

    // ── Worktrees (one per task) ─────────────────────────────────────────

    /**
     * Prepare a task's working copy in the background: worktree on its
     * branch, `.env` with the task's values, then the setup commands.
     * Progress travels as task events (prepare.step / prepare.completed /
     * prepare.failed).
     */
    app.post('/worktrees/:slug/prepare', async (request, reply) => {
        const slug = slugSchema.parse((request.params as { slug?: string }).slug);
        const body = prepareSchema.parse(request.body);
        if (preparing.has(slug)) {
            return reply.code(202).send({ status: 'preparing' });
        }
        preparing.add(slug);
        deps.outbox.setSeqOffset(body.task_id, body.seq_offset);
        const emit = (type: 'prepare.step' | 'prepare.completed' | 'prepare.failed', data: Record<string, unknown>): void => {
            deps.outbox.push(body.task_id, { type, data });
        };

        void (async () => {
            try {
                emit('prepare.step', { step: 'worktree' });
                const worktree = await prepareWorktree(layout, { slug, branch: body.branch, baseBranch: body.base_branch, env: body.env });
                writeEnv(worktree.path, body.env);
                saveTaskEnv(worktree.path, body.env);

                let setup: 'ok' | 'failed' | 'skipped' = 'skipped';
                let log = '';
                if (worktree.created && deps.setupScript !== undefined) {
                    emit('prepare.step', { step: 'setup' });
                    const scriptEnv = body.setup_ref !== undefined ? { ...body.env, ARGOS_SETUP_REF: body.setup_ref } : body.env;
                    const result = await deps.setupScript(worktree.path, scriptEnv, SETUP_TIMEOUT_MS);
                    setup = result.code === 0 ? 'ok' : 'failed';
                    log = tail(result.output);
                }
                emit('prepare.completed', { path: worktree.path, base_commit: worktree.baseCommit, created: worktree.created, setup, log });
                if (gates !== null) {
                    void gates.ensureBaseline(worktree.baseCommit);
                }
            } catch (error) {
                emit('prepare.failed', {
                    code: error instanceof CodedError ? error.code : 'internal_error',
                    message: (error instanceof Error ? error.message : String(error)).slice(0, 4000),
                });
            } finally {
                preparing.delete(slug);
            }
        })();

        return reply.code(202).send({ status: 'preparing' });
    });

    /**
     * Run the setup steps of `ref` in a fresh worktree and remove it again —
     * the "setup commands work" part of testing a workspace setup. Answers
     * when done (up to the setup timeout).
     */
    app.post('/setup/check', async (request) => {
        const body = setupCheckSchema.parse(request.body);
        if (deps.setupScript === undefined) {
            return { setup: 'skipped', log: '' };
        }
        if (preparing.has(SETUP_CHECK_SLUG)) {
            throw new CodedError(409, 'setup_check_running', 'A setup check is already running');
        }
        preparing.add(SETUP_CHECK_SLUG);
        try {
            await removeWorktree(layout, SETUP_CHECK_SLUG);
            const worktree = await prepareWorktree(layout, { slug: SETUP_CHECK_SLUG, branch: 'argos/setup-check', baseBranch: body.ref, env: body.env });
            writeEnv(worktree.path, body.env);
            const result = await deps.setupScript(worktree.path, body.env, SETUP_TIMEOUT_MS);

            return { setup: result.code === 0 ? 'ok' : 'failed', log: tail(result.output) };
        } finally {
            await removeWorktree(layout, SETUP_CHECK_SLUG);
            preparing.delete(SETUP_CHECK_SLUG);
        }
    });

    app.delete('/worktrees/:slug', async (request, reply) => {
        const slug = slugSchema.parse((request.params as { slug?: string }).slug);
        const dir = worktreeDir(layout, slug);
        // Removing the worktree pulls it from under any open session, even an idle one.
        if (sessionsIn(dir).some((s) => s.currentState !== 'closed')) {
            throw new CodedError(409, 'task_running', 'A session still works in this task');
        }
        await removeWorktree(layout, slug);
        checkpointStores.delete(dir);
        fileIndexes.delete(dir);

        return reply.code(202).send({});
    });

    app.get('/worktrees/:slug/files', async (request) => {
        const dir = worktreeOf(request);
        const query = z.object({ q: z.string().max(200).default(''), limit: z.coerce.number().int().min(1).max(50).default(20) }).parse(request.query);

        return { files: await filesFor(dir).search(query.q, query.limit) };
    });

    app.get('/worktrees/:slug/files/content', async (request) => {
        const dir = worktreeOf(request);
        const query = z.object({ path: z.string().min(1).max(1000) }).parse(request.query);

        return readWorkspaceFile(dir, query.path);
    });

    app.get('/worktrees/:slug/git/status', async (request) => gitOps.status(worktreeOf(request)));

    app.get('/worktrees/:slug/git/diff', async (request) => {
        const dir = worktreeOf(request);
        const query = request.query as { base?: string; path?: string };
        const base = refSchema.parse(query.base);
        const path = query.path === undefined ? null : pathSchema.parse(query.path);

        return gitOps.diffAgainst(dir, base, 512 * 1024, path);
    });

    app.get('/worktrees/:slug/git/changes', async (request) => {
        const dir = worktreeOf(request);
        const base = refSchema.parse((request.query as { base?: string }).base);

        return gitOps.changedFiles(dir, base);
    });

    app.post('/worktrees/:slug/git/commit', async (request, reply) => {
        const dir = worktreeOf(request);
        const body = commitSchema.parse(request.body);
        const result = await gitOps.commit(dir, body.subject, body.body, body.author);
        if (result === null) {
            throw new CodedError(409, 'no_changes', 'Nothing to commit');
        }

        return reply.code(201).send(result);
    });

    app.post('/worktrees/:slug/git/push', async (request, reply) => {
        const dir = worktreeOf(request);
        const { branch } = z.object({ branch: refSchema }).parse(request.body);
        await gitOps.push(dir, branch);

        return reply.code(202).send({});
    });

    app.post('/worktrees/:slug/checkpoints', async (request, reply) => {
        const dir = worktreeOf(request);
        const body = z.object({ task_id: idSchema, label: z.string().max(120).default('') }).parse(request.body);
        const checkpoint = await checkpointsFor(dir).create(body.task_id, body.label);
        announceCheckpoint(body.task_id, checkpoint, null);

        return reply.code(201).send(checkpoint);
    });

    app.get('/worktrees/:slug/checkpoints/diff', async (request) => {
        const dir = worktreeOf(request);
        const { ref } = z.object({ ref: checkpointRefSchema }).parse(request.query);

        return checkpointsFor(dir).diff(ref);
    });

    app.post('/worktrees/:slug/checkpoints/restore', async (request) => {
        const dir = worktreeOf(request);
        const { ref } = z.object({ ref: checkpointRefSchema }).parse(request.body);
        const { taskId } = parseRef(ref);
        // A restore rewrites this task's working tree: its session must be quiet.
        if (sessionsIn(dir).some((s) => s.currentState !== 'idle' && s.currentState !== 'closed')) {
            throw new CodedError(409, 'task_running', 'Claude is working in this task; interrupt it first');
        }
        const result = await checkpointsFor(dir).restore(ref);
        announceCheckpoint(taskId, result.before, null);

        return result;
    });

    app.post('/worktrees/:slug/gates/run', async (request, reply) => {
        const dir = worktreeOf(request);
        const body = z.object({
            run_id: idSchema,
            base_commit: z.string().regex(/^[0-9a-f]{40}$/).nullish(),
            // Re-check one gate only (the others come back as skip).
            only: z.string().regex(GATE_NAME_PATTERN).nullish(),
        }).parse(request.body);
        void gateRunner().start(body.run_id, dir, body.base_commit ?? null, body.only ?? null);

        return reply.code(202).send({ run_id: body.run_id });
    });

    app.get('/worktrees/:slug/gates/fix-prompt', async (request) => {
        const dir = worktreeOf(request);
        const query = z.object({ gate: z.string().regex(GATE_NAME_PATTERN), run_id: idSchema }).parse(request.query);

        return { prompt: await gateRunner().fixPrompt(query.gate, query.run_id, dir) };
    });

    app.get('/worktrees/:slug/gates/log', async (request) => {
        const dir = worktreeOf(request);
        const query = z.object({ gate: z.string().regex(GATE_NAME_PATTERN), run_id: idSchema }).parse(request.query);

        return gateRunner().log(query.gate, query.run_id, dir);
    });

    // ── Task sessions ────────────────────────────────────────────────────

    app.post('/tasks/:id/start', async (request) => {
        const id = idSchema.parse((request.params as { id?: string }).id);
        const body = startSchema.parse(request.body);

        const existing = sessions.get(id);
        if (existing !== undefined && existing.currentState !== 'closed') {
            return { state: existing.currentState };
        }
        const cwd = worktreeDir(layout, body.worktree);
        if (!existsSync(join(cwd, '.git'))) {
            throw new CodedError(409, 'worktree_missing', 'This task has no working copy yet');
        }

        deps.outbox.setSeqOffset(id, body.seq_offset);
        const session = new TaskSession(
            id,
            {
                cwd,
                guard: body.guard,
                agentId: body.agent_id,
                oauthToken: body.oauth_token,
                model: body.model,
                effort: body.effort ?? null,
                permissionMode: body.permission_mode,
                resumeSessionId: body.resume_session_id ?? null,
                appendSystemPrompt: body.append_system_prompt,
                gitAuthor: body.git_author ?? null,
            },
            (event) => deps.outbox.push(id, event),
            approvals,
            deps.queryFactory,
            deps.environment,
            async (clientMsgId) => announceCheckpoint(id, await checkpointsFor(cwd).create(id, ''), clientMsgId),
        );
        sessions.set(id, session);
        session.start();

        return { state: session.currentState };
    });

    app.post('/tasks/:id/messages', async (request, reply: FastifyReply) => {
        const session = sessionFor(request);
        const body = messageSchema.parse(request.body);
        if (session.currentState === 'closed') {
            throw new CodedError(409, 'task_closed', 'Task session is closed; start it again');
        }
        const accepted = session.send({ clientMsgId: body.client_msg_id, author: body.author, text: body.text, images: body.images });

        return reply.code(202).send({ queued: accepted });
    });

    app.post('/tasks/:id/interrupt', async (request, reply) => {
        await sessionFor(request).interrupt();

        return reply.code(202).send({});
    });

    app.post('/tasks/:id/approvals/:approvalId', async (request, reply) => {
        const session = sessionFor(request);
        const approvalId = z.string().min(1).parse((request.params as { approvalId?: string }).approvalId);
        const body = approvalSchema.parse(request.body);
        approvals.resolve(session.taskId, approvalId, { decision: body.decision, updatedInput: body.updated_input, message: body.message, remember: body.remember });

        return reply.code(202).send({});
    });

    app.post('/tasks/:id/permission-mode', async (request, reply) => {
        const session = sessionFor(request);
        const { mode } = z.object({ mode: permissionMode }).parse(request.body);
        await session.setPermissionMode(mode);

        return reply.code(202).send({});
    });

    app.post('/tasks/:id/model', async (request, reply) => {
        const session = sessionFor(request);
        const { model } = z.object({ model: z.string().min(1) }).parse(request.body);
        await session.setModel(model);

        return reply.code(202).send({});
    });

    app.post('/tasks/:id/effort', async (request, reply) => {
        const session = sessionFor(request);
        const { effort } = z.object({ effort: effortLevel.nullable() }).parse(request.body);
        await session.setEffort(effort);

        return reply.code(202).send({});
    });

    app.post('/tasks/:id/stop', async (request, reply) => {
        const session = sessionFor(request);
        await session.stop();
        sessions.delete(session.taskId);

        return reply.code(202).send({});
    });

    return { app, sessions };
}
