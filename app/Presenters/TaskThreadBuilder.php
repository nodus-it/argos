<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Support\ConceptMarkdown;
use App\Support\CostFormatter;
use App\Support\Workflow\TaskStage;
use Illuminate\Support\Str;

/**
 * Builds the chronological task thread (the task-created entry, then every
 * phase run interleaved with the feedback that triggered it) that drives
 * <x-argos.thread>. Lifted out of ViewTask so the Filament page stays UI wiring.
 */
class TaskThreadBuilder
{
    /**
     * @return list<array<string, mixed>>
     */
    public function build(Task $task, TaskStage $stage): array
    {
        $items = [[
            'kind' => 'created',
            'title' => __('tasks.view.thread.created'),
            'who' => __('tasks.view.thread.you'),
            'time' => $task->created_at?->diffForHumans(),
            'body' => Str::limit($task->description ?? '', 400),
        ]];

        $runs = $task->phaseRuns()
            ->whereIn('phase', ['concept', 'implement', 'push', 'respond'])
            ->orderBy('id')
            ->get();

        /** @var array<string, int> $counts */
        $counts = $runs->groupBy(fn (PhaseRun $r): string => $r->phase->value)
            ->map->count()
            ->all();

        // The diff renders the live workspace, so it only makes sense on the
        // most recent code-producing run (implement or respond).
        $latestCodeRunId = $runs->whereIn('phase', [Phase::Implement, Phase::Respond])->last()?->id;

        foreach ($runs as $run) {
            $phase = $run->phase->value;

            $notes = match ($phase) {
                'concept' => $run->concept_notes,
                'implement', 'respond' => $run->implement_notes,
                default => null,
            };
            if ($notes !== null && trim($notes) !== '') {
                $items[] = [
                    'kind' => 'feedback',
                    'who' => __('tasks.view.thread.you'),
                    'time' => $run->started_at?->diffForHumans(),
                    'body' => $notes,
                ];
            }

            $items[] = $this->phaseItem($run, $counts[$phase] ?? 1, $run->id === $latestCodeRunId, $task);
        }

        // First run of a phase that's been dispatched but not yet picked up by
        // a worker has no phase_run row yet — show a placeholder so the thread
        // reflects "the system is about to work on this".
        if ($stage->isQueued() && ($phase = $stage->phase()?->value) !== null
            && ! $runs->contains(fn (PhaseRun $r): bool => $r->phase->value === $phase)) {
            $items[] = [
                'kind' => 'phase',
                'phase' => $phase,
                'iteration' => 1,
                'title' => $this->phaseTitle($phase, 1, 1),
                'state' => 'queued',
                'who' => __('tasks.view.thread.agent'),
                'time' => null,
                'cost' => null,
                'body' => __('tasks.view.thread.queued_body'),
                'error' => null,
                'conceptHtml' => null,
                'summaryHtml' => null,
                'techHtml' => null,
                'qualityGates' => null,
                'qualityGateLogKeys' => [],
                'qualityGateLastKeys' => [],
                'iterationKey' => null,
                'hasStoredLog' => false,
                'isLive' => false,
                'showDiff' => false,
                'prUrl' => null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseItem(PhaseRun $run, int $count, bool $isLatestCode, Task $task): array
    {
        $phase = $run->phase->value;
        $state = match ($run->status) {
            PhaseStatus::Running, PhaseStatus::RateLimited => 'running',
            PhaseStatus::Pending => 'queued',
            PhaseStatus::Paused => 'paused',
            PhaseStatus::Failed, PhaseStatus::QualityGateFailed, PhaseStatus::LockBlocked => 'failed',
            default => 'done',
        };
        $busy = in_array($state, ['running', 'queued'], true);

        $error = $state === 'failed' && $run->error_log !== null && trim($run->error_log) !== ''
            ? Str::limit(trim($run->error_log), 400)
            : null;

        $conceptHtml = null;
        $summaryHtml = null;
        $techHtml = null;
        $qualityGates = null;
        $qualityGateLogKeys = [];
        $qualityGateLastKeys = [];
        $prUrl = null;

        if ($phase === 'concept') {
            if ($run->concept_md !== null) {
                $conceptHtml = Str::markdown(ConceptMarkdown::stripOuterCodeFence($run->concept_md));
            }
            $body = $error ?? ($busy ? __('tasks.view.thread.concept_running_body') : __('tasks.view.thread.concept_body'));
        } elseif (in_array($phase, ['implement', 'respond'], true)) {
            if ($run->implement_summary_nontechnical !== null) {
                $summaryHtml = Str::markdown($run->implement_summary_nontechnical);
            }
            if ($run->implement_summary_technical !== null) {
                $techHtml = Str::markdown($run->implement_summary_technical);
            }
            $qualityGates = $run->result_json['quality_gates'] ?? null;
            $qualityGateLogKeys = array_keys($run->quality_gate_logs ?? []);
            $qualityGateLastKeys = $this->qualityGateLastKeys($qualityGates, $qualityGateLogKeys);
            $short = $run->implement_summary_nontechnical !== null
                ? Str::limit(trim(strip_tags(Str::markdown($run->implement_summary_nontechnical))), 180)
                : null;
            $body = $error ?? $short ?? ($busy ? __('tasks.view.thread.implement_running_body') : __('tasks.view.thread.implement_body'));
        } else { // push
            $prUrl = $run->result_json['pr_url'] ?? $task->pr_url;
            $body = $error ?? ($busy ? __('tasks.view.thread.push_running_body') : __('tasks.view.thread.push_body'));
        }

        return [
            'kind' => 'phase',
            'phase' => $phase,
            'iteration' => $run->iteration,
            'title' => $this->phaseTitle($phase, $run->iteration, $count),
            'state' => $state,
            'who' => __('tasks.view.thread.agent'),
            'time' => ($run->finished_at ?? $run->started_at)?->diffForHumans(),
            'cost' => $run->cost_usd !== null ? CostFormatter::usd((float) $run->cost_usd) : null,
            'body' => $body,
            'error' => $error,
            'conceptHtml' => $conceptHtml,
            'summaryHtml' => $summaryHtml,
            'techHtml' => $techHtml,
            'qualityGates' => $qualityGates,
            'qualityGateLogKeys' => $qualityGateLogKeys,
            // Per failed gate, the latest matching log key (or null) for linking
            // to its quality-gate log — the filter/sort kept out of the view.
            'qualityGateLastKeys' => $qualityGateLastKeys,
            // Lazy-load key for the stored stream log of this iteration.
            'iterationKey' => $run->stream_log !== null ? $phase.'.'.$run->iteration : null,
            'hasStoredLog' => $run->stream_log !== null,
            'isLive' => $state === 'running',
            'showDiff' => $isLatestCode && in_array($phase, ['implement', 'respond'], true),
            'prUrl' => $prUrl,
        ];
    }

    /**
     * For each failed gate, the latest log key matching it (exact or `gate.*`),
     * or null when there is none. Non-failed gates are omitted.
     *
     * @param  array<string, string>|null  $qualityGates  gate => result
     * @param  list<string>  $logKeys
     * @return array<string, string|null>
     */
    private function qualityGateLastKeys(?array $qualityGates, array $logKeys): array
    {
        $lastKeys = [];

        foreach ($qualityGates ?? [] as $gate => $result) {
            if (! in_array($result, ['fail', 'advisory_fail'], true)) {
                continue;
            }

            $matches = array_values(array_filter(
                $logKeys,
                fn (string $k): bool => $k === $gate || str_starts_with($k, $gate.'.'),
            ));
            sort($matches);
            $lastKeys[$gate] = $matches !== [] ? end($matches) : null;
        }

        return $lastKeys;
    }

    private function phaseTitle(string $phase, int $iteration, int $count): string
    {
        $base = match ($phase) {
            'concept' => __('tasks.view.thread.concept'),
            'implement' => __('tasks.view.thread.implement'),
            'respond' => __('tasks.view.thread.respond'),
            'push' => __('tasks.view.thread.push'),
            default => ucfirst($phase),
        };

        return $count > 1 ? $base.' v'.$iteration : $base;
    }
}
