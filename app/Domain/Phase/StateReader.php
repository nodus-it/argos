<?php

declare(strict_types=1);

namespace App\Domain\Phase;

use App\Domain\Task\WorkflowService;
use App\Models\PhaseRun;
use App\Models\Task;
use Symfony\Component\Process\Process;

class StateReader
{
    /**
     * Read state.json from a task's workspace volume (used for tests and CLI diagnostics).
     */
    public function read(string $taskName): ?array
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace:ro',
            'alpine',
            'cat', '/workspace/.agent/state.json',
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($process->getOutput(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getPhaseStatus(string $taskName, string $phase): ?string
    {
        $state = $this->read($taskName);

        return $state['phases'][$phase]['current_status'] ?? null;
    }

    /**
     * Read concept markdown from the DB (task.concept_md).
     */
    public function readConcept(Task $task): ?string
    {
        return $task->concept_md ?: null;
    }

    /**
     * Read current notes from the DB (task.concept_notes).
     */
    public function readNotes(Task $task): ?string
    {
        return $task->concept_notes ?: null;
    }

    /**
     * Write notes to the DB (task.concept_notes).
     * PhaseRunner copies them to the volume before the next concept phase.
     */
    public function writeNotes(Task $task, string $content): bool
    {
        $task->update(['concept_notes' => $content ?: null]);

        return true;
    }

    /**
     * Read notes history from concept phase_runs (newest first).
     *
     * @return array<int, array{timestamp: string, content: string}>
     */
    public function readNotesHistory(Task $task): array
    {
        return $task->phaseRuns()
            ->where('phase', 'concept')
            ->whereNotNull('concept_notes')
            ->orderBy('iteration', 'desc')
            ->get()
            ->map(fn (PhaseRun $run) => [
                'timestamp' => "Iteration {$run->iteration} · ".($run->finished_at?->format('d.m.Y H:i') ?? '—'),
                'content' => $run->concept_notes,
            ])
            ->all();
    }

    /**
     * Read concept version history from concept phase_runs (newest first, excluding current).
     *
     * @return array<int, array{timestamp: string, content: string}>
     */
    public function readConceptHistory(Task $task, ?int $currentIteration = null): array
    {
        return $task->phaseRuns()
            ->where('phase', 'concept')
            ->whereNotNull('concept_md')
            ->when($currentIteration !== null, fn ($q) => $q->where('iteration', '!=', $currentIteration))
            ->orderBy('iteration', 'desc')
            ->get()
            ->map(fn (PhaseRun $run) => [
                'timestamp' => $run->finished_at?->format('d.m.Y H:i') ?? '—',
                'content' => $run->concept_md,
            ])
            ->all();
    }

    /**
     * Read implement summary version history from phase_runs (newest first, excluding current).
     *
     * @return array<int, array{timestamp: string, nontechnical: string|null, technical: string|null}>
     */
    public function readImplementHistory(Task $task, ?int $currentIteration = null): array
    {
        return $task->phaseRuns()
            ->where('phase', 'implement')
            ->where(function ($q): void {
                $q->whereNotNull('implement_summary_nontechnical')
                    ->orWhereNotNull('implement_summary_technical');
            })
            ->when($currentIteration !== null, fn ($q) => $q->where('iteration', '!=', $currentIteration))
            ->orderBy('iteration', 'desc')
            ->get()
            ->map(fn (PhaseRun $run) => [
                'timestamp' => $run->finished_at?->format('d.m.Y H:i') ?? '—',
                'nontechnical' => $run->implement_summary_nontechnical,
                'technical' => $run->implement_summary_technical,
            ])
            ->all();
    }

    /**
     * Read implement notes history from implement phase_runs (newest first).
     *
     * @return array<int, array{timestamp: string, content: string}>
     */
    public function readImplementNotesHistory(Task $task): array
    {
        return $task->phaseRuns()
            ->where('phase', 'implement')
            ->whereNotNull('implement_notes')
            ->orderBy('iteration', 'desc')
            ->get()
            ->map(fn (PhaseRun $run) => [
                'timestamp' => "Iteration {$run->iteration} · ".($run->finished_at?->format('d.m.Y H:i') ?? '—'),
                'content' => $run->implement_notes,
            ])
            ->all();
    }

    /**
     * List phase run iterations that have a stream log stored, descending.
     *
     * @return list<int>
     */
    public function listLogIterations(Task $task, string $phase): array
    {
        return $task->phaseRuns()
            ->where('phase', $phase)
            ->whereNotNull('stream_log')
            ->orderBy('iteration', 'desc')
            ->pluck('iteration')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Parse a stream log stored in phase_runs.stream_log into displayable lines.
     *
     * @return array<int, array{text: string, class: string}>
     */
    public function readStreamLogIteration(Task $task, string $phase, int $iteration): array
    {
        $phaseRun = $task->phaseRuns()
            ->where('phase', $phase)
            ->where('iteration', $iteration)
            ->first();

        if ($phaseRun === null || $phaseRun->stream_log === null) {
            return [];
        }

        return $this->parseStreamLog($phaseRun->stream_log);
    }

    /**
     * Fix phase runs stuck in 'running' for more than 2 hours and sync pr_url from result_json.
     * Pure DB operation — no Docker calls.
     */
    public function syncToDb(Task $task): void
    {
        app(WorkflowService::class)->markStaleRunsAsFailed($task);

        $pushRun = $task->phaseRuns()
            ->where('phase', 'push')
            ->where('status', 'completed')
            ->latest('iteration')
            ->first();

        if ($pushRun !== null) {
            $updates = [];
            $prUrl = $pushRun->result_json['pr_url'] ?? null;
            if ($prUrl !== null && $prUrl !== '' && $prUrl !== $task->pr_url) {
                $updates['pr_url'] = $prUrl;
            }
            $branch = $pushRun->result_json['branch'] ?? null;
            if ($branch !== null && $branch !== $task->feature_branch) {
                $updates['feature_branch'] = $branch;
            }
            if ($updates !== []) {
                $task->update($updates);
            }
        }
    }

    /**
     * @return array<int, array{text: string, class: string}>
     */
    private function parseStreamLog(string $streamLog): array
    {
        $lines = [];
        foreach (explode("\n", $streamLog) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $event = json_decode($raw, true);
            if (! is_array($event)) {
                continue;
            }

            if ($event['type'] === 'assistant') {
                foreach ($event['message']['content'] ?? [] as $block) {
                    if ($block['type'] === 'text' && ($block['text'] ?? '') !== '') {
                        $lines[] = ['text' => $block['text'], 'class' => 'text-slate-300'];
                    } elseif ($block['type'] === 'tool_use') {
                        $input = $block['input'] ?? [];
                        $detail = $input['file_path'] ?? $input['command'] ?? json_encode($input);
                        if (strlen((string) $detail) > 120) {
                            $detail = substr((string) $detail, 0, 120);
                        }
                        $lines[] = ['text' => "[tool:{$block['name']}] {$detail}", 'class' => 'text-sky-400'];
                    }
                }
            } elseif ($event['type'] === 'result' && isset($event['total_cost_usd'])) {
                $cost = number_format((float) $event['total_cost_usd'], 6);
                $lines[] = ['text' => "[INFO] Abgeschlossen · Kosten: \${$cost}", 'class' => 'text-emerald-400'];
            }
        }

        return $lines;
    }

    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}
