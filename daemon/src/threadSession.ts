import { mkdirSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';
import type { Options, PermissionMode, PermissionResult, SDKUserMessage } from '@anthropic-ai/claude-agent-sdk';
import { ApprovalRegistry } from './approvals.js';
import type { EventSink, PendingEvent, ThreadState } from './events.js';
import { InputQueue } from './inputQueue.js';
import { mapCommands, Normalizer, type SdkMessageLike } from './normalize.js';
import { extractResetAt, isUsageLimitMessage } from './usageLimit.js';

/** The parts of the SDK's `Query` the session relies on. */
export interface QueryLike extends AsyncIterable<SdkMessageLike> {
    interrupt(): Promise<unknown>;
    setPermissionMode(mode: PermissionMode): Promise<void>;
    setModel(model?: string): Promise<void>;
    supportedCommands(): Promise<unknown[]>;
    close(): void;
}

export type QueryFactory = (params: { prompt: AsyncIterable<SDKUserMessage>; options: Options }) => QueryLike;

export const USER_PERMISSION_MODES = ['default', 'acceptEdits', 'plan', 'bypassPermissions'] as const;
export type UserPermissionMode = (typeof USER_PERMISSION_MODES)[number];

export interface ThreadStartParams {
    agentId: string;
    oauthToken: string;
    model: string;
    permissionMode: UserPermissionMode;
    resumeSessionId?: string | null;
    appendSystemPrompt?: string;
}

export interface SessionEnvironment {
    workspaceDir: string;
    agentsDir: string;
    claudeBin?: string;
    /** Base environment for the claude child process (defaults to process.env). */
    baseEnv?: NodeJS.ProcessEnv;
}

export interface MessageImage {
    media_type: string;
    data: string;
}

export interface IncomingMessage {
    clientMsgId: string;
    author: { id: string | number; name: string };
    text: string;
    images?: MessageImage[];
}

interface QueuedMessage {
    clientMsgId: string;
    message: SDKUserMessage;
}

/** Variables the claude child must never inherit. */
const STRIPPED_ENV = ['ARGOS_DAEMON_TOKEN', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'CLAUDE_CODE_OAUTH_TOKEN', 'CLAUDE_CONFIG_DIR'];

/** How long after an interrupt an ended query counts as "ended by the interrupt". */
const INTERRUPT_RESTART_WINDOW_MS = 30_000;

/**
 * One interactive Claude session for one Argos thread.
 *
 * The session runs a single SDK `query()` in streaming-input mode for as long
 * as the thread is open: follow-up messages are pushed into the input queue,
 * tool permissions are relayed to humans through `canUseTool`, and every SDK
 * message is normalized into daemon events.
 */
export class ThreadSession {
    private readonly normalizer = new Normalizer();
    private queue: InputQueue<QueuedMessage>;
    private query: QueryLike | null = null;
    private state: ThreadState = 'closed';
    private stopped = false;
    private claudeSessionId: string | null;
    private lastInterruptAt = 0;
    private readonly seenClientMsgIds = new Set<string>();
    private stderrTail: string[] = [];
    private loop: Promise<void> | null = null;

    constructor(
        readonly threadId: string,
        private params: ThreadStartParams,
        private readonly emit: EventSink,
        private readonly approvals: ApprovalRegistry,
        private readonly queryFactory: QueryFactory,
        private readonly environment: SessionEnvironment,
        /** Runs when the SDK takes a message, before Claude sees it (checkpoints). */
        private readonly beforeTurn: (clientMsgId: string) => Promise<void> = async () => {},
    ) {
        this.claudeSessionId = params.resumeSessionId ?? null;
        this.queue = this.newQueue();
    }

    get currentState(): ThreadState {
        return this.state;
    }

    get sessionId(): string | null {
        return this.claudeSessionId;
    }

    /** Resolves when the SDK loop has ended (tests, shutdown). */
    get finished(): Promise<void> {
        return this.loop ?? Promise.resolve();
    }

    start(): void {
        if (this.query !== null) {
            return;
        }
        this.stopped = false;
        this.setState('starting');
        this.launch();
    }

    send(message: IncomingMessage): boolean {
        if (this.seenClientMsgIds.has(message.clientMsgId)) {
            return false;
        }
        this.seenClientMsgIds.add(message.clientMsgId);

        const text = `[${message.author.name}]: ${message.text}`;
        const content: SDKUserMessage['message']['content'] =
            message.images === undefined || message.images.length === 0
                ? text
                : [
                      { type: 'text', text },
                      ...message.images.map((image) => ({
                          type: 'image' as const,
                          source: { type: 'base64' as const, media_type: image.media_type as 'image/png', data: image.data },
                      })),
                  ];

        this.queue.push({
            clientMsgId: message.clientMsgId,
            message: { type: 'user', message: { role: 'user', content }, parent_tool_use_id: null, uuid: randomUUID() },
        });

        return true;
    }

    async interrupt(): Promise<void> {
        this.lastInterruptAt = Date.now();
        for (const approvalId of this.approvals.cancelThread(this.threadId, 'Interrupted by user', true)) {
            this.emit({ type: 'approval.cancelled', data: { approval_id: approvalId, reason: 'interrupted' } });
        }
        await this.query?.interrupt();
    }

    async setPermissionMode(mode: UserPermissionMode): Promise<void> {
        this.params = { ...this.params, permissionMode: mode };
        await this.query?.setPermissionMode(mode);
    }

    async setModel(model: string): Promise<void> {
        this.params = { ...this.params, model };
        await this.query?.setModel(model);
    }

    /** Close the session for good. */
    async stop(): Promise<void> {
        this.stopped = true;
        for (const approvalId of this.approvals.cancelThread(this.threadId, 'Session closed', true)) {
            this.emit({ type: 'approval.cancelled', data: { approval_id: approvalId, reason: 'closed' } });
        }
        this.queue.close();
        this.query?.close();
        await this.loop;
        this.setState('closed');
    }

    private newQueue(): InputQueue<QueuedMessage> {
        return new InputQueue<QueuedMessage>((item) => {
            this.emit({ type: 'user.accepted', data: { client_msg_id: item.clientMsgId } });
            this.setState('running');
        });
    }

    private launch(): void {
        const configDir = join(this.environment.agentsDir, this.params.agentId);
        mkdirSync(configDir, { recursive: true });

        const env: Record<string, string | undefined> = { ...(this.environment.baseEnv ?? process.env) };
        for (const key of STRIPPED_ENV) {
            delete env[key];
        }
        env.CLAUDE_CODE_OAUTH_TOKEN = this.params.oauthToken;
        env.CLAUDE_CONFIG_DIR = configDir;

        const queue = this.queue;
        const beforeTurn = this.beforeTurn;
        const prompt: AsyncIterable<SDKUserMessage> = {
            async *[Symbol.asyncIterator]() {
                for await (const item of queue.iterable()) {
                    // A failing hook must never cost the user their message.
                    await beforeTurn(item.clientMsgId).catch(() => {});
                    yield item.message;
                }
            },
        };

        const options: Options = {
            cwd: this.environment.workspaceDir,
            includePartialMessages: true,
            permissionMode: this.params.permissionMode,
            allowDangerouslySkipPermissions: true,
            model: this.params.model,
            settingSources: ['project', 'local'],
            systemPrompt: { type: 'preset', preset: 'claude_code', append: this.params.appendSystemPrompt ?? '' },
            canUseTool: (toolName, input, options) => this.canUseTool(toolName, input, options),
            env,
            stderr: (data: string) => this.captureStderr(data),
        };
        if (this.environment.claudeBin !== undefined && this.environment.claudeBin !== '') {
            options.pathToClaudeCodeExecutable = this.environment.claudeBin;
        }
        if (this.claudeSessionId !== null) {
            options.resume = this.claudeSessionId;
        }

        const query = this.queryFactory({ prompt, options });
        this.query = query;
        this.loop = this.consume(query);
    }

    private async consume(query: QueryLike): Promise<void> {
        let failure: unknown = null;
        try {
            for await (const message of query) {
                await this.handle(message, query);
            }
        } catch (error) {
            failure = error;
        }

        this.query = null;
        if (this.stopped) {
            return;
        }

        if (failure === null && this.claudeSessionId !== null && Date.now() - this.lastInterruptAt < INTERRUPT_RESTART_WINDOW_MS) {
            // The query ended because of an interrupt: continue the same Claude
            // session with whatever is still queued.
            const pending = this.queue.drain();
            this.queue = this.newQueue();
            for (const item of pending) {
                this.queue.push(item);
            }
            this.setState('idle');
            this.launch();

            return;
        }

        const message = failure instanceof Error ? failure.message : failure === null ? 'Session ended' : String(failure);
        const detail = [message, ...this.stderrTail].join('\n');
        if (isUsageLimitMessage(detail)) {
            this.emit({ type: 'usage_limit', data: { resets_at: extractResetAt(detail) } });
        }
        this.emit({ type: 'error', data: { code: failure === null ? 'session_ended' : 'session_failed', message: detail.slice(0, 4000) } });
        this.queue.close();
        this.queue = this.newQueue();
        this.setState('closed');
    }

    private async handle(message: SdkMessageLike, query: QueryLike): Promise<void> {
        if (message.type === 'system' && message.subtype === 'init' && typeof message.session_id === 'string') {
            this.claudeSessionId = message.session_id;
            const events = this.normalizer.normalize(message);
            const commands = await query.supportedCommands().then(mapCommands, () => null);
            for (const event of events) {
                if (event.type === 'session.started' && commands !== null) {
                    event.data.commands = commands;
                }
                this.emit(event);
            }
            if (this.state === 'starting') {
                this.setState('idle');
            }

            return;
        }

        for (const event of this.normalizer.normalize(message)) {
            if (event.type === 'status') {
                this.setState(event.data.state as ThreadState);
                continue;
            }
            this.emit(event);
        }

        if (message.type === 'result') {
            this.setState(this.queue.size > 0 ? 'running' : 'idle');
        }
    }

    private async canUseTool(
        toolName: string,
        input: Record<string, unknown>,
        options: { signal: AbortSignal; suggestions?: unknown[]; toolUseID: string; title?: string; displayName?: string; description?: string; decisionReason?: string; blockedPath?: string },
    ): Promise<PermissionResult> {
        const { approvalId, decision } = this.approvals.open(this.threadId, options.signal, (id) =>
            this.emit({ type: 'approval.cancelled', data: { approval_id: id, reason: 'aborted' } }),
        );
        const event: PendingEvent = {
            type: 'approval.requested',
            data: {
                approval_id: approvalId,
                tool_use_id: options.toolUseID,
                kind: ApprovalRegistry.kindFor(toolName),
                tool_name: toolName,
                input,
                suggestions: options.suggestions ?? [],
                title: options.title ?? null,
                display_name: options.displayName ?? null,
                description: options.description ?? null,
                decision_reason: options.decisionReason ?? null,
                blocked_path: options.blockedPath ?? null,
            },
        };
        this.emit(event);
        this.setState('waiting_input');

        const result = await decision;
        if (this.approvals.countFor(this.threadId) === 0 && !this.stopped) {
            this.setState('running');
        }

        if (result.decision === 'allow') {
            return { behavior: 'allow', updatedInput: result.updatedInput ?? input, toolUseID: options.toolUseID };
        }

        return { behavior: 'deny', message: result.message ?? 'Denied by user', interrupt: result.interrupt ?? false, toolUseID: options.toolUseID };
    }

    private setState(state: ThreadState): void {
        if (this.state === state) {
            return;
        }
        this.state = state;
        this.emit({ type: 'status', data: { state } });
    }

    private captureStderr(data: string): void {
        this.stderrTail.push(...data.split('\n').filter((line) => line.trim() !== ''));
        if (this.stderrTail.length > 20) {
            this.stderrTail = this.stderrTail.slice(-20);
        }
    }
}
