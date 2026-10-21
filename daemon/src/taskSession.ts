import { randomUUID } from 'node:crypto';
import type { Options, PermissionMode, PermissionResult, PermissionUpdate, SDKUserMessage } from '@anthropic-ai/claude-agent-sdk';
import { ApprovalRegistry } from './approvals.js';
import { applyClaudeBin, claudeChildEnv, type SessionEnvironment } from './claudeEnv.js';
import type { EventSink, PendingEvent, TaskState } from './events.js';
import { InputQueue } from './inputQueue.js';
import { mapCommands, mapModels, Normalizer, type SdkMessageLike } from './normalize.js';
import { extractResetAt, isUsageLimitMessage } from './usageLimit.js';

/** The parts of the SDK's `Query` the session relies on. */
export interface QueryLike extends AsyncIterable<SdkMessageLike> {
    interrupt(): Promise<unknown>;
    setPermissionMode(mode: PermissionMode): Promise<void>;
    setModel(model?: string): Promise<void>;
    supportedCommands(): Promise<unknown[]>;
    /** Models the session may use (aliases resolve to the newest model). */
    supportedModels?(): Promise<unknown[]>;
    close(): void;
}

export type QueryFactory = (params: { prompt: AsyncIterable<SDKUserMessage>; options: Options }) => QueryLike;

export const USER_PERMISSION_MODES = ['default', 'acceptEdits', 'plan', 'bypassPermissions'] as const;
export type UserPermissionMode = (typeof USER_PERMISSION_MODES)[number];

export interface TaskStartParams {
    agentId: string;
    oauthToken: string;
    model: string;
    permissionMode: UserPermissionMode;
    resumeSessionId?: string | null;
    appendSystemPrompt?: string;
}

export type { SessionEnvironment } from './claudeEnv.js';

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

/** How long after an interrupt an ended query counts as "ended by the interrupt". */
const INTERRUPT_RESTART_WINDOW_MS = 30_000;

/**
 * One interactive Claude session for one Argos task.
 *
 * The session runs a single SDK `query()` in streaming-input mode for as long
 * as the task is open: follow-up messages are pushed into the input queue,
 * tool permissions are relayed to humans through `canUseTool`, and every SDK
 * message is normalized into daemon events.
 */
export class TaskSession {
    private readonly normalizer = new Normalizer();
    private queue: InputQueue<QueuedMessage>;
    private query: QueryLike | null = null;
    private state: TaskState = 'closed';
    private stopped = false;
    private claudeSessionId: string | null;
    private lastInterruptAt = 0;
    private readonly seenClientMsgIds = new Set<string>();
    private stderrTail: string[] = [];
    private loop: Promise<void> | null = null;

    constructor(
        readonly taskId: string,
        private params: TaskStartParams,
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

    get currentState(): TaskState {
        return this.state;
    }

    get sessionId(): string | null {
        return this.claudeSessionId;
    }

    /** Launch the SDK query; call once per session. */
    start(): void {
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
        for (const approvalId of this.approvals.cancelTask(this.taskId, 'Interrupted by user')) {
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
        for (const approvalId of this.approvals.cancelTask(this.taskId, 'Session closed')) {
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
        const env = claudeChildEnv(this.environment, this.params.agentId, this.params.oauthToken);
        // Only this query's stderr belongs in its failure report.
        this.stderrTail = [];

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
        applyClaudeBin(options, this.environment);
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
        // A closed session is never relaunched: the HTTP layer starts a new one.
        this.queue.close();
        this.setState('closed');
    }

    private async handle(message: SdkMessageLike, query: QueryLike): Promise<void> {
        if (message.type === 'system' && message.subtype === 'init' && typeof message.session_id === 'string') {
            this.claudeSessionId = message.session_id;
            const events = this.normalizer.normalize(message);
            const commands = await query.supportedCommands().then(mapCommands, () => null);
            const models = query.supportedModels !== undefined ? await query.supportedModels().then(mapModels, () => null) : null;
            for (const event of events) {
                if (event.type === 'session.started' && commands !== null) {
                    event.data.commands = commands;
                }
                if (event.type === 'session.started' && models !== null && models.length > 0) {
                    event.data.models = models;
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
                this.setState(event.data.state as TaskState);
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
        const { approvalId, decision } = this.approvals.open(this.taskId, options.signal, (id) =>
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
        if (this.approvals.countFor(this.taskId) === 0 && !this.stopped) {
            this.setState('running');
        }

        if (result.decision === 'allow') {
            // "Always allow" hands Claude Code's own suggestions back (session rules).
            const remember = result.remember === true && Array.isArray(options.suggestions) && options.suggestions.length > 0;
            return {
                behavior: 'allow',
                updatedInput: result.updatedInput ?? input,
                toolUseID: options.toolUseID,
                ...(remember ? { updatedPermissions: options.suggestions as PermissionUpdate[] } : {}),
            };
        }

        return { behavior: 'deny', message: result.message ?? 'Denied by user', interrupt: result.interrupt ?? false, toolUseID: options.toolUseID };
    }

    private setState(state: TaskState): void {
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
