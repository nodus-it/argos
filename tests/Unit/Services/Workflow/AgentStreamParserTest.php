<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workflow;

use App\Services\Workflow\AgentStreamParser;
use Tests\TestCase;

class AgentStreamParserTest extends TestCase
{
    private function parse(string $raw, int $maxEvents = 200): array
    {
        return (new AgentStreamParser)->parse($raw, $maxEvents);
    }

    private function assistant(array $content): string
    {
        return (string) json_encode(['type' => 'assistant', 'message' => ['content' => $content]]);
    }

    public function test_parses_assistant_text_block(): void
    {
        $events = $this->parse($this->assistant([['type' => 'text', 'text' => 'Hello']]));

        $this->assertCount(1, $events);
        $this->assertSame('text', $events[0]['kind']);
        $this->assertSame('Hello', $events[0]['text']);
    }

    public function test_parses_thinking_block(): void
    {
        $events = $this->parse($this->assistant([['type' => 'thinking', 'thinking' => 'Let me reason']]));

        $this->assertSame('thinking', $events[0]['kind']);
        $this->assertSame('Let me reason', $events[0]['text']);
        $this->assertFalse($events[0]['truncated']);
    }

    public function test_truncates_long_content_and_keeps_full(): void
    {
        $long = str_repeat('x', 2000);
        $events = $this->parse($this->assistant([['type' => 'thinking', 'thinking' => $long]]));

        $this->assertTrue($events[0]['truncated']);
        $this->assertSame(600, mb_strlen($events[0]['text']));
        $this->assertNotNull($events[0]['full']);
        $this->assertGreaterThan(600, mb_strlen((string) $events[0]['full']));
    }

    public function test_tool_use_summary_prefers_file_path_then_command(): void
    {
        $events = $this->parse(implode("\n", [
            $this->assistant([['type' => 'tool_use', 'name' => 'Read', 'id' => 't1', 'input' => ['file_path' => '/app/x.php']]]),
            $this->assistant([['type' => 'tool_use', 'name' => 'Bash', 'id' => 't2', 'input' => ['command' => 'ls -la']]]),
        ]));

        $this->assertSame('tool_use', $events[0]['kind']);
        $this->assertSame('Read', $events[0]['tool']);
        $this->assertSame('/app/x.php', $events[0]['summary']);
        $this->assertSame('ls -la', $events[1]['summary']);
    }

    public function test_tool_result_is_paired_to_its_tool_use(): void
    {
        $raw = implode("\n", [
            $this->assistant([['type' => 'tool_use', 'name' => 'Read', 'id' => 'tool-1', 'input' => ['file_path' => '/x']]]),
            (string) json_encode([
                'type' => 'user',
                'message' => ['content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tool-1', 'content' => '142 lines', 'is_error' => false],
                ]],
            ]),
        ]);

        $events = $this->parse($raw);

        $this->assertCount(1, $events, 'paired result must not produce a standalone event');
        $this->assertSame('tool_use', $events[0]['kind']);
        $this->assertNotNull($events[0]['result']);
        $this->assertSame('142 lines', $events[0]['result']['text']);
        $this->assertFalse($events[0]['result']['is_error']);
    }

    public function test_orphan_tool_result_becomes_standalone_event(): void
    {
        $raw = (string) json_encode([
            'type' => 'user',
            'message' => ['content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'gone', 'content' => 'orphan output', 'is_error' => true],
            ]],
        ]);

        $events = $this->parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('tool_result', $events[0]['kind']);
        $this->assertTrue($events[0]['is_error']);
        $this->assertSame('orphan output', $events[0]['text']);
    }

    public function test_tool_result_content_array_is_flattened(): void
    {
        $raw = (string) json_encode([
            'type' => 'user',
            'message' => ['content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => [
                    ['type' => 'text', 'text' => 'line one'],
                    ['type' => 'text', 'text' => 'line two'],
                ]],
            ]],
        ]);

        $events = $this->parse($raw);

        $this->assertSame("line one\nline two", $events[0]['text']);
    }

    public function test_non_json_lines_become_argos_events_with_level(): void
    {
        $raw = implode("\n", [
            '[INFO] concept: calling agent',
            '[WARN] concept: retrying',
            '[ERROR] boom',
            'plain diagnostic line',
        ]);

        $events = $this->parse($raw);

        $this->assertSame('argos', $events[0]['kind']);
        $this->assertSame('info', $events[0]['level']);
        $this->assertSame('concept: calling agent', $events[0]['text']);
        $this->assertSame('warn', $events[1]['level']);
        $this->assertSame('error', $events[2]['level']);
        $this->assertSame('info', $events[3]['level']);
    }

    public function test_preserves_interleaved_order_of_argos_and_agent(): void
    {
        $raw = implode("\n", [
            '[INFO] starting',
            $this->assistant([['type' => 'text', 'text' => 'working']]),
            '[INFO] done',
        ]);

        $events = $this->parse($raw);

        $this->assertSame(['argos', 'text', 'argos'], array_column($events, 'kind'));
    }

    public function test_result_event_exposes_cost_and_tokens(): void
    {
        $raw = (string) json_encode([
            'type' => 'result',
            'result' => 'all good',
            'total_cost_usd' => 0.1234,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);

        $events = $this->parse($raw);

        $this->assertSame('result', $events[0]['kind']);
        $this->assertSame(0.1234, $events[0]['cost']);
        $this->assertSame(150, $events[0]['tokens']);
    }

    public function test_ignores_blank_and_malformed_lines(): void
    {
        $raw = implode("\n", ['', '   ', '{not json', $this->assistant([['type' => 'text', 'text' => 'ok']])]);

        $events = $this->parse($raw);

        // The blank lines are skipped; '{not json' is a non-JSON argos line.
        $kinds = array_column($events, 'kind');
        $this->assertContains('text', $kinds);
        $this->assertSame('ok', $events[array_search('text', $kinds, true)]['text']);
    }

    public function test_caps_event_count_keeping_the_tail(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = $this->assistant([['type' => 'text', 'text' => "msg {$i}"]]);
        }

        $events = $this->parse(implode("\n", $lines), 10);

        $this->assertCount(10, $events);
        $this->assertSame('msg 49', $events[9]['text']);
        $this->assertSame('msg 40', $events[0]['text']);
    }

    public function test_strips_ansi_escape_codes(): void
    {
        $events = $this->parse("\033[32m[INFO] green text\033[0m");

        $this->assertSame('argos', $events[0]['kind']);
        $this->assertSame('green text', $events[0]['text']);
    }

    public function test_suppresses_final_text_that_duplicates_the_result(): void
    {
        $raw = implode("\n", [
            $this->assistant([['type' => 'text', 'text' => 'Working on it…']]),
            $this->assistant([['type' => 'text', 'text' => 'Done: created HELLO.md']]),
            (string) json_encode(['type' => 'result', 'result' => 'Done: created HELLO.md', 'total_cost_usd' => 0.01]),
        ]);

        $events = $this->parse($raw);
        $kinds = array_column($events, 'kind');

        // The interim text stays, the final deliverable text is dropped, the
        // result line remains (rendered as a cost summary, not the text).
        $this->assertSame(['text', 'result'], $kinds);
        $this->assertSame('Working on it…', $events[0]['text']);
    }

    public function test_keeps_interim_text_identical_to_an_earlier_message(): void
    {
        // Only the LAST matching text is the deliverable; an earlier identical
        // line is coincidental and must survive.
        $raw = implode("\n", [
            $this->assistant([['type' => 'text', 'text' => 'recheck']]),
            $this->assistant([['type' => 'tool_use', 'name' => 'Bash', 'id' => 'b1', 'input' => ['command' => 'ls']]]),
            $this->assistant([['type' => 'text', 'text' => 'recheck']]),
            (string) json_encode(['type' => 'result', 'result' => 'recheck']),
        ]);

        $events = $this->parse($raw);
        $texts = array_values(array_filter($events, fn ($e) => $e['kind'] === 'text'));

        $this->assertCount(1, $texts);
    }

    public function test_filters_composer_install_noise(): void
    {
        $raw = implode("\n", [
            '[INFO] concept: composer install',
            'Installing dependencies from lock file (including require-dev)',
            'Package operations: 109 installs, 0 updates, 0 removals',
            '  - Installing doctrine/inflector (2.1.0): Extracting archive',
            '  - Installing symfony/console (v7.0.0): Extracting archive',
            '  laravel/pail .......................................................',
            '78 packages you are using are looking for funding.',
            'Use the `composer fund` command to find out more!',
            '[INFO] concept: calling agent',
        ]);

        $events = $this->parse($raw);
        $texts = array_column($events, 'text');

        // Only the two real [INFO] orchestration lines survive.
        $this->assertSame(['concept: composer install', 'concept: calling agent'], $texts);
    }
}
