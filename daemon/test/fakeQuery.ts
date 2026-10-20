import type { Options, PermissionMode, PermissionResult, SDKUserMessage } from '@anthropic-ai/claude-agent-sdk';
import type { SdkMessageLike } from '../src/normalize.js';
import type { QueryFactory, QueryLike } from '../src/threadSession.js';

export interface ScriptContext {
    readonly options: Options;
    readonly interrupted: boolean;
    askPermission(toolName: string, input: Record<string, unknown>, toolUseID?: string): Promise<PermissionResult>;
}

/** Produces the SDK messages answering one user message. */
export type Script = (message: SDKUserMessage, ctx: ScriptContext) => AsyncGenerator<SdkMessageLike>;

export function textOfUserMessage(message: SDKUserMessage): string {
    const content = message.message.content;
    if (typeof content === 'string') {
        return content;
    }

    return content.map((block) => (block.type === 'text' ? block.text : `[${block.type}]`)).join(' ');
}

/** A stand-in for the SDK's Query: deterministic, in-process. */
export class FakeQuery implements QueryLike {
    interrupted = false;
    closed = false;
    readonly permissionModes: PermissionMode[] = [];
    readonly models: (string | undefined)[] = [];
    endAfterInterrupt = false;
    private abort = new AbortController();

    constructor(
        readonly prompt: AsyncIterable<SDKUserMessage>,
        readonly options: Options,
        private readonly script: Script,
        readonly sessionId = 'session-1',
    ) {}

    async interrupt(): Promise<void> {
        this.interrupted = true;
        this.abort.abort();
    }

    async setPermissionMode(mode: PermissionMode): Promise<void> {
        this.permissionModes.push(mode);
    }

    async setModel(model?: string): Promise<void> {
        this.models.push(model);
    }

    async supportedCommands(): Promise<unknown[]> {
        return [{ name: 'review', description: 'Review the diff', argumentHint: '[path]' }];
    }

    close(): void {
        this.closed = true;
    }

    async *[Symbol.asyncIterator](): AsyncGenerator<SdkMessageLike> {
        yield {
            type: 'system',
            subtype: 'init',
            session_id: this.sessionId,
            model: this.options.model,
            permissionMode: this.options.permissionMode,
            claude_code_version: '2.1.304',
            slash_commands: ['review'],
        };

        for await (const message of this.prompt) {
            if (this.closed) {
                return;
            }
            this.interrupted = false;
            this.abort = new AbortController();
            const ctx: ScriptContext = {
                options: this.options,
                interrupted: false,
                askPermission: (toolName, input, toolUseID = 'toolu_1') => {
                    const canUseTool = this.options.canUseTool;
                    if (canUseTool === undefined) {
                        return Promise.resolve({ behavior: 'allow', updatedInput: input });
                    }

                    return canUseTool(toolName, input, { signal: this.abort.signal, toolUseID, requestId: `req_${toolUseID}` }) as Promise<PermissionResult>;
                },
            };
            Object.defineProperty(ctx, 'interrupted', { get: () => this.interrupted });

            for await (const out of this.script(message, ctx)) {
                yield out;
                if (this.interrupted) {
                    break;
                }
            }
            if (this.interrupted && this.endAfterInterrupt) {
                return;
            }
            yield result(this.sessionId, this.interrupted ? 'error_during_execution' : 'success');
        }
    }
}

export function result(sessionId: string, subtype = 'success'): SdkMessageLike {
    return {
        type: 'result',
        subtype,
        is_error: subtype !== 'success',
        session_id: sessionId,
        total_cost_usd: 0.0123,
        usage: { input_tokens: 10, output_tokens: 5 },
        duration_ms: 42,
        num_turns: 1,
        result: 'ok',
    };
}

export function assistantText(id: string, text: string): SdkMessageLike {
    return { type: 'assistant', parent_tool_use_id: null, message: { id, content: [{ type: 'text', text }] } };
}

export function streamDelta(id: string, text: string): SdkMessageLike[] {
    return [
        { type: 'stream_event', parent_tool_use_id: null, event: { type: 'message_start', message: { id } } },
        { type: 'stream_event', parent_tool_use_id: null, event: { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text } } },
    ];
}

/** Records every query the session creates. */
export function fakeFactory(script: Script, configure: (query: FakeQuery) => void = () => {}): { factory: QueryFactory; queries: FakeQuery[] } {
    const queries: FakeQuery[] = [];
    const factory: QueryFactory = ({ prompt, options }) => {
        const query = new FakeQuery(prompt, options, script, `session-${queries.length + 1}`);
        configure(query);
        queries.push(query);

        return query;
    };

    return { factory, queries };
}

/** Poll until `predicate` holds (event loop driven, no timers to fake). */
export async function waitFor(predicate: () => boolean, timeoutMs = 2000): Promise<void> {
    const started = Date.now();
    while (!predicate()) {
        if (Date.now() - started > timeoutMs) {
            throw new Error('waitFor timed out');
        }
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
}
