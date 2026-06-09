<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use Illuminate\Support\Str;

/**
 * Parses the agent's stream-json transcript into typed, displayable events for
 * the CLI-near live/history view.
 *
 * Two inputs feed the same parser so the live view (.bg.log) and the stored
 * transcript (phase_runs.stream_log) render identically:
 *
 *   - The worker mirrors the full, token-scrubbed stream-json to its stderr,
 *     which the manager captures into the task's `.bg.log`. That file also
 *     carries the worker's own bash log lines (`[INFO] …`) — those become
 *     `argos` events, every JSON line becomes an agent event.
 *   - `phase_runs.stream_log` persists that same `.bg.log` per iteration, so
 *     the historical view is as rich as the live one (orchestration + agent).
 *
 * Each line is classified by event type rather than by string matching, so
 * thinking / text / tool calls / tool results are cleanly separated.
 *
 * @phpstan-type ToolResult array{text: string, full: ?string, truncated: bool, is_error: bool}
 * @phpstan-type AgentEvent array{
 *     kind: 'argos'|'thinking'|'text'|'tool_use'|'tool_result'|'result',
 *     text: string,
 *     level?: string,
 *     tool?: string,
 *     summary?: string,
 *     input_full?: ?string,
 *     full?: ?string,
 *     truncated?: bool,
 *     is_error?: bool,
 *     result?: ToolResult|null,
 *     cost?: float,
 *     tokens?: int,
 * }
 */
class AgentStreamParser
{
    /** Max characters shown inline before content becomes collapsible. */
    private const PREVIEW_LIMIT = 600;

    /** Hard cap on stored full content, to keep the Livewire payload bounded. */
    private const FULL_LIMIT = 6000;

    /**
     * Parse a raw stream (mixed argos lines + JSON events) into events.
     *
     * @return list<AgentEvent>
     */
    public function parse(string $raw, int $maxEvents = 200): array
    {
        /** @var list<AgentEvent> $events */
        $events = [];

        // tool_use id => index in $events, so a later tool_result can attach.
        $toolIndex = [];

        foreach (explode("\n", $raw) as $line) {
            $line = $this->stripAnsi(rtrim($line, "\r"));
            if (trim($line) === '') {
                continue;
            }

            $event = $this->decodeJson($line);
            if ($event === null) {
                // Not JSON → a worker (argos) log line or a CLI diagnostic.
                // Composer's per-package install spam drowns the actual work, so
                // it is dropped entirely.
                if ($this->isComposerNoise($line)) {
                    continue;
                }
                $events[] = $this->argosEvent($line);

                continue;
            }

            $type = $event['type'] ?? null;

            if ($type === 'assistant') {
                foreach ($event['message']['content'] ?? [] as $block) {
                    $rendered = $this->assistantBlock($block);
                    if ($rendered === null) {
                        continue;
                    }
                    $events[] = $rendered;
                    if ($rendered['kind'] === 'tool_use' && isset($block['id']) && is_string($block['id'])) {
                        $toolIndex[$block['id']] = array_key_last($events);
                    }
                }
            } elseif ($type === 'user') {
                foreach ($event['message']['content'] ?? [] as $block) {
                    if (($block['type'] ?? null) !== 'tool_result') {
                        continue;
                    }
                    $this->attachToolResult($events, $toolIndex, $block);
                }
            } elseif ($type === 'result') {
                $events[] = $this->resultEvent($event);
            }
        }

        $this->suppressFinalDeliverable($events);

        if (count($events) > $maxEvents) {
            $events = array_values(array_slice($events, -$maxEvents));
        }

        return $events;
    }

    /**
     * The agent's final message is the phase deliverable (the concept, the
     * implement summary) — already shown verbatim in its own panel and echoed
     * by the `result` event. Drop the trailing `text` event that duplicates it
     * so the log shows the work, not a second copy of the output.
     *
     * @param  list<AgentEvent>  $events
     */
    private function suppressFinalDeliverable(array &$events): void
    {
        $resultText = null;
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]['kind'] === 'result') {
                $resultText = trim($events[$i]['text'] ?? '');
                break;
            }
        }

        if ($resultText === null || $resultText === '') {
            return;
        }

        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]['kind'] === 'text' && trim($events[$i]['text'] ?? '') === $resultText) {
                array_splice($events, $i, 1);

                return;
            }
        }
    }

    /**
     * Composer's dependency-install output (one line per package, plus the
     * package-discovery dotted lines) is pure noise in the agent log.
     */
    private function isComposerNoise(string $line): bool
    {
        $trimmed = ltrim($line);

        return preg_match('/^-\s+(Installing|Downloading|Extracting|Upgrading|Updating|Removing)\b/', $trimmed) === 1
            || str_contains($line, 'Extracting archive')
            || preg_match('/^(Package operations:|Installing dependencies|Updating dependencies|Lock file operations|Loading composer|Nothing to (install|update)|Generating optimized autoload|Verifying lock file|Do not run Composer)/', $trimmed) === 1
            || preg_match('/^\d+ packages? (you are using|are looking)/', $trimmed) === 1
            || str_contains($line, 'composer fund')
            || preg_match('/\.{6,}/', $line) === 1
            || preg_match('/^> /', $trimmed) === 1;
    }

    /**
     * Render a single assistant content block.
     *
     * @param  array<string, mixed>  $block
     * @return AgentEvent|null
     */
    private function assistantBlock(array $block): ?array
    {
        $type = $block['type'] ?? null;

        if ($type === 'text') {
            $text = trim((string) ($block['text'] ?? ''));

            return $text === '' ? null : ['kind' => 'text', 'text' => $text];
        }

        if ($type === 'thinking') {
            $text = trim((string) ($block['thinking'] ?? $block['text'] ?? ''));
            if ($text === '') {
                return null;
            }
            [$preview, $full, $truncated] = $this->truncate($text);

            return ['kind' => 'thinking', 'text' => $preview, 'full' => $full, 'truncated' => $truncated];
        }

        if ($type === 'tool_use') {
            $input = is_array($block['input'] ?? null) ? $block['input'] : [];

            return [
                'kind' => 'tool_use',
                'text' => '',
                'tool' => (string) ($block['name'] ?? 'tool'),
                'summary' => $this->toolSummary($input),
                'input_full' => $this->toolInputFull($input),
                'result' => null,
            ];
        }

        return null;
    }

    /**
     * Attach a tool_result to its tool_use, or append it standalone if the
     * matching call is outside the parsed window.
     *
     * @param  list<AgentEvent>  $events
     * @param  array<string, int>  $toolIndex
     * @param  array<string, mixed>  $block
     */
    private function attachToolResult(array &$events, array $toolIndex, array $block): void
    {
        $text = $this->toolResultText($block['content'] ?? '');
        $isError = (bool) ($block['is_error'] ?? false);
        [$preview, $full, $truncated] = $this->truncate($text);

        /** @var ToolResult $result */
        $result = ['text' => $preview, 'full' => $full, 'truncated' => $truncated, 'is_error' => $isError];

        $id = $block['tool_use_id'] ?? null;
        if (is_string($id) && isset($toolIndex[$id]) && isset($events[$toolIndex[$id]])) {
            $events[$toolIndex[$id]]['result'] = $result;

            return;
        }

        $events[] = [
            'kind' => 'tool_result',
            'text' => $preview,
            'full' => $full,
            'truncated' => $truncated,
            'is_error' => $isError,
        ];
    }

    /**
     * A non-JSON line: a worker bash log (`[INFO] …`) or a CLI diagnostic.
     *
     * @return AgentEvent
     */
    private function argosEvent(string $line): array
    {
        $level = 'info';
        if (preg_match('/^\[(DEBUG|INFO|WARN|ERROR)\]\s?(.*)$/s', $line, $m) === 1) {
            $level = strtolower($m[1]);
            $line = $m[2];
        } elseif (Str::contains($line, ['FAILED', 'error', 'Error'])) {
            $level = 'error';
        }

        return ['kind' => 'argos', 'level' => $level, 'text' => $line];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return AgentEvent
     */
    private function resultEvent(array $event): array
    {
        $cost = (float) ($event['total_cost_usd'] ?? 0.0);
        $usage = is_array($event['usage'] ?? null) ? $event['usage'] : [];
        $tokens = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);

        return ['kind' => 'result', 'text' => (string) ($event['result'] ?? ''), 'cost' => $cost, 'tokens' => $tokens];
    }

    /**
     * Short, human-readable summary of a tool's input (file path, command, …).
     *
     * @param  array<string, mixed>  $input
     */
    private function toolSummary(array $input): string
    {
        foreach (['file_path', 'path', 'command', 'pattern', 'url', 'description', 'query'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && (string) $input[$key] !== '') {
                return (string) $input[$key];
            }
        }

        $encoded = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : Str::limit($encoded, 120);
    }

    /**
     * The full pretty-printed input, only when it carries more than the summary.
     *
     * @param  array<string, mixed>  $input
     */
    private function toolInputFull(array $input): ?string
    {
        if ($input === []) {
            return null;
        }
        $keys = array_keys($input);
        // A single short scalar key is already fully shown in the summary.
        if (count($keys) === 1 && is_scalar($input[$keys[0]]) && mb_strlen((string) $input[$keys[0]]) <= 120) {
            return null;
        }

        $pretty = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($pretty === false) {
            return null;
        }

        return mb_substr($pretty, 0, self::FULL_LIMIT);
    }

    /**
     * Flatten a tool_result `content` (string, or array of text blocks).
     */
    private function toolResultText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text') {
                    $parts[] = (string) ($block['text'] ?? '');
                } elseif (is_string($block)) {
                    $parts[] = $block;
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }

    /**
     * @return array{0: string, 1: ?string, 2: bool} [preview, full|null, truncated]
     */
    private function truncate(string $text): array
    {
        if (mb_strlen($text) <= self::PREVIEW_LIMIT) {
            return [$text, null, false];
        }

        return [
            mb_substr($text, 0, self::PREVIEW_LIMIT),
            mb_substr($text, 0, self::FULL_LIMIT),
            true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $line): ?array
    {
        $line = ltrim($line);
        if ($line === '' || $line[0] !== '{') {
            return null;
        }
        $decoded = json_decode($line, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function stripAnsi(string $line): string
    {
        return (string) preg_replace('/\033\[[0-9;]*[mGKHFABCDJsu]/', '', $line);
    }
}
