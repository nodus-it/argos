<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\TaskEventType;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a task's event list into what the chat renders: a person's
 * messages, and per Claude turn a head (duration, cost), compact step rows
 * for tool calls and the text/approval/notice events in between.
 * Approvals that were simply allowed disappear — the step row says enough.
 */
final class TaskTimeline
{
    /**
     * @param  Collection<int, TaskEvent>  $events  chronological
     * @param  Collection<string, TaskEvent>  $toolResults  by tool_use_id
     * @param  Collection<string, TaskApproval>  $approvals  by id
     * @return list<array<string, mixed>>
     */
    public static function build(Collection $events, Collection $toolResults, Collection $approvals, bool $busy): array
    {
        $blocks = [];
        $turn = null;
        $lastSessionCost = 0.0;

        $closeSteps = function () use (&$turn): void {
            if ($turn !== null && $turn['steps'] !== []) {
                $turn['items'][] = ['kind' => 'steps', 'rows' => $turn['steps']];
                $turn['steps'] = [];
            }
        };
        $closeTurn = function () use (&$turn, &$blocks, $closeSteps): void {
            if ($turn !== null) {
                $closeSteps();
                unset($turn['steps']);
                $blocks[] = $turn;
                $turn = null;
            }
        };

        foreach ($events as $event) {
            $p = $event->payload;
            if ($event->type === TaskEventType::UserMessage) {
                $closeTurn();
                $blocks[] = ['kind' => 'user', 'event' => $event];

                continue;
            }
            if (in_array($event->type, [TaskEventType::ToolResult, TaskEventType::StatusChanged], true)) {
                continue;
            }

            $turn ??= ['kind' => 'claude', 'started_at' => $event->created_at, 'duration_ms' => null, 'cost' => null, 'running' => false, 'items' => [], 'steps' => [], 'step_count' => 0];

            switch ($event->type) {
                case TaskEventType::ToolCall:
                    $result = $toolResults->get((string) ($p['tool_use_id'] ?? ''));
                    $turn['steps'][] = self::step($event, $result, $busy);
                    $turn['step_count']++;
                    break;

                case TaskEventType::TurnCompleted:
                    $session = (float) ($p['session_cost_usd'] ?? 0);
                    $turn['duration_ms'] = isset($p['duration_ms']) ? (int) $p['duration_ms'] : null;
                    $turn['cost'] = $session >= $lastSessionCost ? $session - $lastSessionCost : $session;
                    $lastSessionCost = $session;
                    $closeTurn();
                    break;

                case TaskEventType::ApprovalRequested:
                    $approval = $approvals->get((string) ($p['approval_id'] ?? ''));
                    if ($approval === null || self::silent($approval)) {
                        break;
                    }
                    $closeSteps();
                    $turn['items'][] = ['kind' => 'event', 'event' => $event];
                    break;

                case TaskEventType::ApprovalResolved:
                    if (($p['status'] ?? '') === ApprovalStatus::Cancelled->value) {
                        $closeSteps();
                        $turn['items'][] = ['kind' => 'event', 'event' => $event];
                    }
                    break;

                default:
                    $closeSteps();
                    $turn['items'][] = ['kind' => 'event', 'event' => $event];
            }
        }

        if ($turn !== null) {
            $turn['running'] = $busy;
            $closeTurn();
        }

        return $blocks;
    }

    /** Tool approvals someone (or a rule) allowed need no card of their own. */
    public static function silent(TaskApproval $approval): bool
    {
        return $approval->kind === ApprovalKind::Tool && $approval->status === ApprovalStatus::Allowed;
    }

    /**
     * One compact row: state, verb, target and a short detail.
     *
     * @return array{id: int, state: string, verb: string, target: string, add: int|null, del: int|null, detail: string, input: mixed, output: string, at: string|null}
     */
    public static function step(TaskEvent $call, ?TaskEvent $result, bool $busy): array
    {
        $tool = (string) ($call->payload['name'] ?? '');
        $input = $call->payload['input'] ?? null;
        $input = is_array($input) ? $input : [];
        $output = (string) ($result?->payload['output'] ?? '');
        $isError = (bool) ($result?->payload['is_error'] ?? false);

        [$add, $del] = self::lineCounts($tool, $input);
        $state = match (true) {
            $result === null && $busy => 'run',
            $result === null => 'idle',
            $isError => 'err',
            default => 'ok',
        };
        $detail = match ($state) {
            'run' => __('workspaces.steps.running'),
            'err' => __('workspaces.steps.failed'),
            default => $add === null && $tool === 'Bash' ? self::firstLine($output) : '',
        };

        return [
            'id' => $call->id,
            'state' => $state,
            'verb' => self::verb($tool),
            'target' => self::target($tool, $input),
            'add' => $add,
            'del' => $del,
            'detail' => $detail,
            'input' => $input,
            'output' => $output,
            'at' => $call->created_at?->format('H:i:s'),
        ];
    }

    public static function verb(string $tool): string
    {
        $key = match ($tool) {
            'Edit', 'MultiEdit', 'NotebookEdit' => 'edited',
            'Write' => 'created',
            'Read' => 'read',
            'Bash' => 'ran',
            'Grep', 'Glob' => 'searched',
            'WebFetch', 'WebSearch' => 'fetched',
            'TodoWrite' => 'todos',
            'Task', 'Agent' => 'agent',
            default => null,
        };

        return $key !== null ? __('workspaces.steps.'.$key) : $tool;
    }

    /** @param  array<string, mixed>  $input */
    private static function target(string $tool, array $input): string
    {
        if ($tool === 'TodoWrite') {
            return '';
        }
        $summary = TaskEventFormatter::toolSummary($tool, $input);

        return Str::limit($summary, 80);
    }

    /**
     * +/− lines for the edit tools, null for everything else.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: int|null, 1: int|null}
     */
    private static function lineCounts(string $tool, array $input): array
    {
        $lines = static fn (mixed $text): int => is_string($text) && $text !== '' ? substr_count($text, "\n") + 1 : 0;

        return match ($tool) {
            'Edit' => [$lines($input['new_string'] ?? ''), $lines($input['old_string'] ?? '')],
            'MultiEdit' => [
                array_sum(array_map(fn ($e): int => is_array($e) ? $lines($e['new_string'] ?? '') : 0, is_array($input['edits'] ?? null) ? $input['edits'] : [])),
                array_sum(array_map(fn ($e): int => is_array($e) ? $lines($e['old_string'] ?? '') : 0, is_array($input['edits'] ?? null) ? $input['edits'] : [])),
            ],
            'Write' => [$lines($input['content'] ?? ''), 0],
            default => [null, null],
        };
    }

    private static function firstLine(string $output): string
    {
        $line = trim(strtok(trim($output), "\n") ?: '');

        return Str::limit($line, 48);
    }

    /** Seconds a running turn has been going, for "working for N s". */
    public static function elapsed(?Carbon $startedAt): int
    {
        return $startedAt === null ? 0 : max(0, (int) $startedAt->diffInSeconds(now()));
    }
}
