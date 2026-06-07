<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\PhaseStatus;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Support\ConceptMarkdown;

/**
 * Persists the artefacts a finished phase left in the worker volume into the
 * database: concept markdown, implement summaries, quality-gate logs, stream
 * logs, stop reasons, error logs and the push branch/PR url. Split out of
 * PhaseRunner so the runner owns only run orchestration while this class owns
 * the per-phase "read volume → write DB" mapping.
 *
 * The host-side `.bg.log` is read by the runner (which owns the log file) and
 * handed in as $bgLog, so this class only ever reads from the volume.
 */
class PhaseResultSync
{
    public function __construct(
        private readonly WorkerVolumeReader $volumeReader,
    ) {}

    /**
     * After a phase completes: read generated content from the volume and store
     * in the DB. Docker is called here (background job), never on page load.
     *
     * @param  string|null  $bgLog  the host-side .bg.log content for this phase,
     *                              read by PhaseRunner (which owns the log file)
     */
    public function sync(Task $task, PhaseRun $phaseRun, string $phase, ?string $notesBeforeRun, ?string $bgLog): void
    {
        if ($phase === 'concept') {
            $conceptMd = $this->volumeReader->readFile($task->volumeName(), '/workspace/.agent/concept.md');
            // Defensive: the agent occasionally wraps its whole reply in a
            // ```markdown … ``` fence despite the system prompt forbidding it.
            // Strip a single outer wrapper so the UI renders the markdown
            // body instead of a giant code block.
            if ($conceptMd !== null) {
                $conceptMd = ConceptMarkdown::stripOuterCodeFence($conceptMd);
            }
            $stateJson = $this->volumeReader->readFile($task->volumeName(), '/workspace/.agent/state.json');

            $phaseRunUpdate = [
                'concept_md' => $conceptMd,
                'concept_notes' => $notesBeforeRun,
            ];

            // When concept fails before Claude runs (e.g. git clone), capture
            // logs/clone.err so the user sees the real reason in the UI.
            // If we have no clone.err but the agent CLI did run and crashed
            // (e.g. 401 from Anthropic), fall back to the captured stderr —
            // the auth error went there, not into the stream-json.
            if ($phaseRun->status !== PhaseStatus::Completed && $conceptMd === null) {
                $cloneErr = $this->volumeReader->readFile($task->volumeName(), '/workspace/.agent/logs/clone.err');
                if ($cloneErr !== null) {
                    $phaseRunUpdate['error_log'] = $cloneErr;
                } else {
                    $stderrPath = "/workspace/.agent/logs/concept.{$phaseRun->iteration}.stderr.log";
                    $stderrLog = $this->volumeReader->readFile($task->volumeName(), $stderrPath);
                    if ($stderrLog !== null && trim($stderrLog) !== '') {
                        $phaseRunUpdate['error_log'] = $stderrLog;
                    }
                }
            }

            // Mirror the implement-phase logic: persist stream_log + stop_reason,
            // and promote a max-turns hit from Failed to Paused. The worker now
            // emits exit 8 (EXIT_MAX_TURNS) directly, which lands as Paused via
            // exitCodeToStatus — but older worker images may still emit exit 1
            // alongside a clean result event, so the stream_log fallback stays.
            $streamLogPath = "/workspace/.agent/logs/concept.{$phaseRun->iteration}.stream.log";
            $streamLog = $this->volumeReader->readFile($task->volumeName(), $streamLogPath);

            // Persist the full .bg.log (orchestration + agent) for the log view,
            // falling back to the agent-only stream from the volume. Stop-reason
            // detection still uses the agent stream (single main session).
            $displayLog = $bgLog ?? $streamLog;
            if ($displayLog !== null) {
                $phaseRunUpdate['stream_log'] = $displayLog;
            }

            if ($streamLog !== null) {
                $stopReason = $this->extractStopReasonFromStreamLog($streamLog);
                if ($stopReason !== null) {
                    $phaseRunUpdate['stop_reason'] = $stopReason;

                    if ($stopReason === 'error_max_turns' && $phaseRun->status === PhaseStatus::Failed) {
                        $phaseRunUpdate['status'] = PhaseStatus::Paused;
                    }
                }
            }

            $phaseRun->update($phaseRunUpdate);

            $taskUpdate = ['concept_notes' => null];
            if ($conceptMd !== null) {
                $taskUpdate['concept_md'] = $conceptMd;
            }
            if ($stateJson !== null) {
                $state = json_decode($stateJson, true);
                $featureBranch = $state['repo']['feature_branch'] ?? null;
                if ($featureBranch !== null && $featureBranch !== $task->feature_branch) {
                    $taskUpdate['feature_branch'] = $featureBranch;
                }
            }
            $task->update($taskUpdate);
        }

        if (in_array($phase, ['implement', 'push'], true)) {
            $streamLogPath = "/workspace/.agent/logs/{$phase}.{$phaseRun->iteration}.stream.log";
            $streamLog = $this->volumeReader->readFile($task->volumeName(), $streamLogPath);

            // Persist the full .bg.log for the log view; fall back to the
            // agent-only volume stream when the host log is unavailable.
            $displayLog = $bgLog ?? $streamLog;
            if ($displayLog !== null) {
                $phaseRun->update(['stream_log' => $displayLog]);
            }

            if ($streamLog !== null) {
                if ($phase === 'implement') {
                    $stopReason = $this->extractStopReasonFromStreamLog($streamLog);
                    if ($stopReason !== null) {
                        $phaseRun->update(['stop_reason' => $stopReason]);

                        // Promote a max-turns hit from "failed" to "paused" so
                        // the UI shows it as resumable, not as an error.
                        if ($stopReason === 'error_max_turns' && $phaseRun->status === PhaseStatus::Failed) {
                            $phaseRun->update(['status' => PhaseStatus::Paused]);
                            $task->update(['current_status' => PhaseStatus::Paused]);
                        }
                    }
                }
            }

            // On failure, surface the CLI's stderr (where auth errors land)
            // in error_log so the UI can show a precise reason instead of
            // just "exit 1". Skip when error_log was already populated.
            // fresh() may be null if the row was removed concurrently (e.g. a
            // job re-attempt cleaning up after a kill) — guard against it, which
            // previously crashed with "read property error_log on null".
            $freshRun = $phaseRun->fresh();
            if ($freshRun !== null
                && $freshRun->status !== PhaseStatus::Completed
                && $freshRun->error_log === null) {
                $stderrPath = "/workspace/.agent/logs/{$phase}.{$phaseRun->iteration}.stderr.log";
                $stderrLog = $this->volumeReader->readFile($task->volumeName(), $stderrPath);
                if ($stderrLog !== null && trim($stderrLog) !== '') {
                    $phaseRun->update(['error_log' => $stderrLog]);
                }
            }
        }

        if (in_array($phase, ['implement', 'respond'], true)) {
            $gateLogs = $this->volumeReader->readQualityGateLogs(
                $task->volumeName(),
                $phaseRun->iteration
            );
            if ($gateLogs !== null && $gateLogs !== []) {
                $phaseRun->update(['quality_gate_logs' => $gateLogs]);
            }
        }

        if ($phase === 'implement') {
            $nontechnical = $this->volumeReader->readFile(
                $task->volumeName(),
                '/workspace/.agent/implement.summary.nontechnical.md'
            );
            $technical = $this->volumeReader->readFile(
                $task->volumeName(),
                '/workspace/.agent/implement.summary.technical.md'
            );

            // Fallback: wenn Claude keine Summary-Dateien geschrieben hat,
            // extrahiere den result-Text aus dem stream_log.
            if ($nontechnical === null && $phaseRun->stream_log !== null) {
                $nontechnical = $this->extractResultTextFromStreamLog($phaseRun->stream_log);
            }

            $phaseRun->update([
                'implement_summary_nontechnical' => $nontechnical,
                'implement_summary_technical' => $technical,
                'implement_notes' => $notesBeforeRun,
            ]);

            $taskUpdate = ['implement_notes' => null];
            if ($nontechnical !== null) {
                $taskUpdate['implement_summary_nontechnical'] = $nontechnical;
            }
            if ($technical !== null) {
                $taskUpdate['implement_summary_technical'] = $technical;
            }
            $task->update($taskUpdate);
        }

        if ($phase === 'push') {
            $resultJson = $phaseRun->result_json;
            $taskUpdate = [];
            if (isset($resultJson['branch']) && $resultJson['branch'] !== $task->feature_branch) {
                $taskUpdate['feature_branch'] = $resultJson['branch'];
            }
            if (isset($resultJson['pr_url']) && $resultJson['pr_url'] !== '' && $resultJson['pr_url'] !== $task->pr_url) {
                $taskUpdate['pr_url'] = $resultJson['pr_url'];
            }
            if ($taskUpdate !== []) {
                $task->update($taskUpdate);
            }
        }
    }

    private function extractStopReasonFromStreamLog(string $streamLog): ?string
    {
        foreach (array_reverse(explode("\n", rtrim($streamLog))) as $line) {
            if ($line === '' || ! str_contains($line, '"type":"result"')) {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && ($event['type'] ?? '') === 'result') {
                $subtype = $event['subtype'] ?? null;

                return is_string($subtype) && $subtype !== '' ? $subtype : null;
            }
        }

        return null;
    }

    private function extractResultTextFromStreamLog(string $streamLog): ?string
    {
        foreach (array_reverse(explode("\n", $streamLog)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && ($event['type'] ?? '') === 'result') {
                $text = trim($event['result'] ?? '');

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }
}
