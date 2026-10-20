import { truncateUtf8, type PendingEvent, type ThreadState } from './events.js';
import { extractResetAt, isUsageLimitMessage, resetsAtFromEpoch } from './usageLimit.js';

/**
 * Structural subset of the SDK message union that the normalizer reads. Kept
 * loose on purpose: the SDK adds message kinds and fields frequently, and
 * anything unknown is simply ignored.
 */
export interface SdkMessageLike {
    type: string;
    subtype?: string;
    [key: string]: unknown;
}

interface ContentBlock {
    type: string;
    [key: string]: unknown;
}

/**
 * Turns the SDK's message stream into Argos daemon events. One instance per
 * thread session; it remembers the id of the assistant message that is
 * currently streaming so deltas can be attributed to it.
 *
 * The categories mirror `app/Services/Workflow/AgentStreamParser.php`
 * (thinking / text / tool_use / tool_result / result) so the manager can reuse
 * its tool-call rendering.
 */
export class Normalizer {
    private streamingMessageId: string | null = null;

    normalize(message: SdkMessageLike): PendingEvent[] {
        switch (message.type) {
            case 'system':
                return this.system(message);
            case 'stream_event':
                return this.streamEvent(message);
            case 'assistant':
                return this.assistant(message);
            case 'user':
                return this.user(message);
            case 'result':
                return this.result(message);
            case 'rate_limit_event':
                return this.rateLimit(message);
            default:
                return [];
        }
    }

    private system(message: SdkMessageLike): PendingEvent[] {
        if (message.subtype === 'init') {
            const commands = Array.isArray(message.slash_commands)
                ? (message.slash_commands as unknown[]).filter((c): c is string => typeof c === 'string')
                : [];

            return [
                {
                    type: 'session.started',
                    data: {
                        claude_session_id: message.session_id,
                        model: message.model,
                        permission_mode: message.permissionMode,
                        claude_code_version: message.claude_code_version,
                        commands: commands.map((name) => ({ name, description: '', argument_hint: '' })),
                    },
                },
            ];
        }

        if (message.subtype === 'commands_changed' && Array.isArray(message.commands)) {
            return [{ type: 'commands.updated', data: { commands: mapCommands(message.commands) } }];
        }

        if (message.subtype === 'session_state_changed' && typeof message.state === 'string') {
            const state: ThreadState | null =
                message.state === 'idle' ? 'idle' : message.state === 'running' ? 'running' : message.state === 'requires_action' ? 'waiting_input' : null;

            return state === null ? [] : [{ type: 'status', data: { state } }];
        }

        return [];
    }

    private streamEvent(message: SdkMessageLike): PendingEvent[] {
        if (message.parent_tool_use_id !== null && message.parent_tool_use_id !== undefined) {
            return [];
        }

        const event = message.event as { type?: string; message?: { id?: string }; delta?: { type?: string; text?: string; thinking?: string } } | undefined;
        if (event === undefined) {
            return [];
        }

        if (event.type === 'message_start') {
            this.streamingMessageId = event.message?.id ?? null;

            return [];
        }

        if (event.type !== 'content_block_delta' || this.streamingMessageId === null) {
            return [];
        }

        if (event.delta?.type === 'text_delta' && typeof event.delta.text === 'string' && event.delta.text !== '') {
            return [{ type: 'assistant.delta', data: { message_id: this.streamingMessageId, text: event.delta.text } }];
        }

        if (event.delta?.type === 'thinking_delta' && typeof event.delta.thinking === 'string' && event.delta.thinking !== '') {
            return [{ type: 'thinking.delta', data: { message_id: this.streamingMessageId, text: event.delta.thinking } }];
        }

        return [];
    }

    private assistant(message: SdkMessageLike): PendingEvent[] {
        const inner = message.message as { id?: string; content?: unknown } | undefined;
        const messageId = inner?.id ?? '';
        const parent = typeof message.parent_tool_use_id === 'string' ? message.parent_tool_use_id : null;
        const events: PendingEvent[] = [];

        if (typeof message.error === 'string') {
            const text = textOf(inner?.content);
            if (message.error === 'rate_limit' || isUsageLimitMessage(text)) {
                events.push({ type: 'usage_limit', data: { resets_at: extractResetAt(text) } });
            } else {
                events.push({ type: 'error', data: { code: message.error, message: text } });
            }

            return events;
        }

        for (const block of blocksOf(inner?.content)) {
            if (block.type === 'text' && typeof block.text === 'string' && parent === null) {
                events.push({ type: 'assistant.message', data: { message_id: messageId, text: block.text } });
            } else if (block.type === 'thinking' && typeof block.thinking === 'string' && parent === null) {
                events.push({ type: 'thinking', data: { message_id: messageId, text: block.thinking } });
            } else if (block.type === 'tool_use') {
                const input = boundedJson(block.input);
                events.push({
                    type: 'tool.started',
                    data: {
                        tool_use_id: block.id,
                        name: block.name,
                        input: input.value,
                        input_truncated: input.truncated,
                        parent_tool_use_id: parent,
                    },
                });
            }
        }

        return events;
    }

    private user(message: SdkMessageLike): PendingEvent[] {
        const inner = message.message as { content?: unknown } | undefined;
        const parent = typeof message.parent_tool_use_id === 'string' ? message.parent_tool_use_id : null;
        const events: PendingEvent[] = [];

        for (const block of blocksOf(inner?.content)) {
            if (block.type !== 'tool_result') {
                continue;
            }
            const output = truncateUtf8(textOf(block.content));
            events.push({
                type: 'tool.result',
                data: {
                    tool_use_id: block.tool_use_id,
                    is_error: block.is_error === true,
                    output: output.text,
                    truncated: output.truncated,
                    parent_tool_use_id: parent,
                },
            });
        }

        return events;
    }

    private result(message: SdkMessageLike): PendingEvent[] {
        this.streamingMessageId = null;
        const events: PendingEvent[] = [];
        const resultText = typeof message.result === 'string' ? message.result : '';
        const errors = Array.isArray(message.errors) ? (message.errors as unknown[]).map(String).join('\n') : '';

        if (message.is_error === true && isUsageLimitMessage(`${resultText}\n${errors}`)) {
            events.push({ type: 'usage_limit', data: { resets_at: extractResetAt(`${resultText}\n${errors}`) } });
        }

        events.push({
            type: 'turn.completed',
            data: {
                subtype: message.subtype,
                is_error: message.is_error === true,
                // Cumulative for the query() call: the manager stores the latest value.
                session_cost_usd: typeof message.total_cost_usd === 'number' ? message.total_cost_usd : 0,
                usage: message.usage ?? null,
                duration_ms: message.duration_ms ?? null,
                num_turns: message.num_turns ?? null,
                errors: errors === '' ? null : errors,
            },
        });

        return events;
    }

    private rateLimit(message: SdkMessageLike): PendingEvent[] {
        const info = message.rate_limit_info as { status?: string; resetsAt?: number; rateLimitType?: string } | undefined;
        if (info?.status !== 'rejected') {
            return [];
        }

        return [{ type: 'usage_limit', data: { resets_at: resetsAtFromEpoch(info.resetsAt), limit_type: info.rateLimitType ?? null } }];
    }
}

export function mapCommands(commands: unknown[]): { name: string; description: string; argument_hint: string }[] {
    return commands
        .filter((c): c is { name: string; description?: string; argumentHint?: string } => typeof c === 'object' && c !== null && typeof (c as { name?: unknown }).name === 'string')
        .map((c) => ({ name: c.name, description: c.description ?? '', argument_hint: c.argumentHint ?? '' }));
}

/** SDK ModelInfo rows → wire shape; `resolved_model` is what an alias points at. */
export function mapModels(models: unknown[]): { value: string; display_name: string; description: string; resolved_model: string | null }[] {
    return models
        .filter((m): m is { value: string; displayName?: string; description?: string; resolvedModel?: string } => typeof m === 'object' && m !== null && typeof (m as { value?: unknown }).value === 'string')
        .map((m) => ({ value: m.value, display_name: m.displayName ?? m.value, description: m.description ?? '', resolved_model: m.resolvedModel ?? null }));
}

function blocksOf(content: unknown): ContentBlock[] {
    if (!Array.isArray(content)) {
        return [];
    }

    return content.filter((block): block is ContentBlock => typeof block === 'object' && block !== null && typeof (block as ContentBlock).type === 'string');
}

/** Flatten string or text-block content into plain text. */
function textOf(content: unknown): string {
    if (typeof content === 'string') {
        return content;
    }

    return blocksOf(content)
        .map((block) => (block.type === 'text' && typeof block.text === 'string' ? block.text : block.type === 'image' ? '[image]' : ''))
        .filter((text) => text !== '')
        .join('\n');
}

function boundedJson(value: unknown): { value: unknown; truncated: boolean } {
    const json = JSON.stringify(value ?? null);
    const bounded = truncateUtf8(json);
    if (!bounded.truncated) {
        return { value: value ?? null, truncated: false };
    }

    return { value: { _truncated_json: bounded.text }, truncated: true };
}
