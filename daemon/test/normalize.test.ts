import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { Normalizer, type SdkMessageLike } from '../src/normalize.js';

const fixture = (name: string): SdkMessageLike[] => JSON.parse(readFileSync(new URL(`./fixtures/${name}`, import.meta.url), 'utf8')) as SdkMessageLike[];

describe('Normalizer', () => {
    it('maps a full turn with a tool call', () => {
        const normalizer = new Normalizer();
        const events = fixture('turn-with-tool.json').flatMap((message) => normalizer.normalize(message));

        expect(events.map((e) => e.type)).toEqual([
            'session.started',
            'status',
            'thinking.delta',
            'assistant.delta',
            'thinking',
            'assistant.message',
            'tool.started',
            'tool.result',
            'tool.started',
            'tool.result',
            'turn.completed',
            'status',
        ]);

        expect(events[0]!.data).toMatchObject({ claude_session_id: '3f1c', model: 'claude-sonnet-5-5', permission_mode: 'default', commands: [{ name: 'compact' }, { name: 'review' }] });
        expect(events[1]!.data).toEqual({ state: 'running' });
        expect(events[3]!.data).toEqual({ message_id: 'msg_01', text: "I'll read " });
        expect(events[5]!.data).toEqual({ message_id: 'msg_01', text: "I'll read the file." });
        expect(events[6]!.data).toMatchObject({ tool_use_id: 'toolu_01', name: 'Read', input: { file_path: '/workspace/app/Foo.php' }, parent_tool_use_id: null });
        expect(events[7]!.data).toMatchObject({ tool_use_id: 'toolu_01', is_error: false, output: '<?php class Foo {}' });
        // Subagent text is dropped, its tool calls are kept with their parent.
        expect(events[8]!.data).toMatchObject({ tool_use_id: 'toolu_sub', parent_tool_use_id: 'toolu_01' });
        expect(events[9]!.data).toMatchObject({ is_error: true, output: 'Exit code 1' });
        expect(events[10]!.data).toMatchObject({ session_cost_usd: 0.0421, num_turns: 2, is_error: false });
        expect(events[11]!.data).toEqual({ state: 'idle' });
    });

    it('maps session_state requires_action to waiting_input', () => {
        expect(new Normalizer().normalize({ type: 'system', subtype: 'session_state_changed', state: 'requires_action' })).toEqual([{ type: 'status', data: { state: 'waiting_input' } }]);
    });

    it('ignores deltas without a message_start and unknown message types', () => {
        const normalizer = new Normalizer();
        expect(normalizer.normalize({ type: 'stream_event', parent_tool_use_id: null, event: { type: 'content_block_delta', delta: { type: 'text_delta', text: 'x' } } })).toEqual([]);
        expect(normalizer.normalize({ type: 'tool_progress' })).toEqual([]);
    });

    it('reports rejected rate limit events as usage_limit', () => {
        const events = new Normalizer().normalize({ type: 'rate_limit_event', rate_limit_info: { status: 'rejected', resetsAt: 1_792_000_000, rateLimitType: 'five_hour' } });
        expect(events).toEqual([{ type: 'usage_limit', data: { resets_at: new Date(1_792_000_000_000).toISOString().replace('.000', ''), limit_type: 'five_hour' } }]);
        expect(new Normalizer().normalize({ type: 'rate_limit_event', rate_limit_info: { status: 'allowed_warning' } })).toEqual([]);
    });

    it('turns assistant API errors into error or usage_limit events', () => {
        const normalizer = new Normalizer();
        expect(normalizer.normalize({ type: 'assistant', error: 'rate_limit', message: { id: 'm', content: [{ type: 'text', text: 'Limit reached' }] } })[0]!.type).toBe('usage_limit');
        expect(normalizer.normalize({ type: 'assistant', error: 'authentication_failed', message: { id: 'm', content: [{ type: 'text', text: 'Invalid token' }] } })).toEqual([
            { type: 'error', data: { code: 'authentication_failed', message: 'Invalid token' } },
        ]);
    });

    it('detects usage limits in error results', () => {
        const events = new Normalizer().normalize({ type: 'result', subtype: 'error_during_execution', is_error: true, result: "You've hit your limit · resets 9pm (UTC)", total_cost_usd: 0 });
        expect(events.map((e) => e.type)).toEqual(['usage_limit', 'turn.completed']);
    });

    it('truncates oversized tool output on a UTF-8 boundary', () => {
        const big = 'ä'.repeat(40_000);
        const [event] = new Normalizer().normalize({ type: 'user', parent_tool_use_id: null, message: { content: [{ type: 'tool_result', tool_use_id: 't', content: big }] } });
        expect(event!.data.truncated).toBe(true);
        expect(Buffer.byteLength(event!.data.output as string)).toBeLessThanOrEqual(64 * 1024);
        expect((event!.data.output as string).endsWith('ä')).toBe(true);
    });

    it('marks oversized tool input as truncated JSON', () => {
        const [event] = new Normalizer().normalize({ type: 'assistant', parent_tool_use_id: null, message: { id: 'm', content: [{ type: 'tool_use', id: 't', name: 'Write', input: { content: 'x'.repeat(70_000) } }] } });
        expect(event!.data.input_truncated).toBe(true);
        expect(event!.data.input).toHaveProperty('_truncated_json');
    });
});
