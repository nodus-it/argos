<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Force-removes the per-run Docker resources (worker + sidecar containers and
 * the ephemeral run network) of a task, identified by the WorkerRunLabels the
 * runner stamps on them. Three callers:
 *   - abort: kill a task's currently running phase immediately (reapTask),
 *   - delete teardown: drop whatever a deleted task left behind (reapTask),
 *   - the scheduled orphan sweep: reap resources of any task that is no longer
 *     running a phase, i.e. left over after a hard process kill (reapExcept).
 *
 * Manager-side only (needs the docker socket). Every docker call is best-effort
 * and never throws — reaping must not break the caller (an abort action, a
 * queued teardown job, a scheduled command).
 */
class RunResourceReaper
{
    /**
     * Remove every run container and network labelled for this task.
     */
    public function reapTask(string $taskId): void
    {
        $filter = WorkerRunLabels::TASK.'='.$taskId;

        $this->removeContainers($this->listIds(['docker', 'ps', '-aq', '--filter', 'label='.$filter]));
        $this->removeNetworks($this->listIds([
            'docker', 'network', 'ls', '-q', '--filter', 'label='.$filter,
        ]));
    }

    /**
     * Reap the run resources of every task whose id is NOT in $keepTaskIds —
     * the orphan sweep. A task currently running a phase keeps its resources;
     * anything else with argos labels is left over from a crash or hard kill.
     *
     * @param  list<string>  $keepTaskIds
     * @return int number of distinct tasks whose resources were reaped
     */
    public function reapExcept(array $keepTaskIds): int
    {
        $keep = array_flip($keepTaskIds);

        $orphans = [];
        foreach ($this->labelledTaskIds() as $taskId) {
            if ($taskId === '' || isset($keep[$taskId]) || isset($orphans[$taskId])) {
                continue;
            }
            $orphans[$taskId] = true;
            $this->reapTask($taskId);
        }

        $count = count($orphans);
        if ($count > 0) {
            Log::channel('argos')->info('Reaped orphaned run resources', [
                'tasks' => array_keys($orphans),
            ]);
        }

        return $count;
    }

    /**
     * The argos.task label value of every run container and network that
     * carries one — the universe the sweep diffs against the running tasks.
     *
     * @return list<string>
     */
    private function labelledTaskIds(): array
    {
        $roleFilter = 'label='.WorkerRunLabels::ROLE;
        $format = '--format';
        $tpl = '{{ index .Labels "'.WorkerRunLabels::TASK.'" }}';

        $containers = $this->runLines([
            'docker', 'ps', '-a', '--filter', $roleFilter, $format, $tpl,
        ]);
        // `docker network ls` template uses .Labels as a comma string, so parse
        // the task id out of it instead of an index lookup.
        $networks = [];
        foreach ($this->runLines(['docker', 'network', 'ls', '--filter', $roleFilter, $format, '{{ .Labels }}']) as $line) {
            $networks[] = $this->taskIdFromLabelString($line);
        }

        return array_values(array_filter(array_merge($containers, $networks), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Pull the argos.task value out of a `docker network ls` `{{ .Labels }}`
     * rendering, which is a flat `k=v,k=v` string.
     */
    private function taskIdFromLabelString(string $labels): string
    {
        foreach (explode(',', $labels) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === WorkerRunLabels::TASK) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $ids
     */
    private function removeContainers(array $ids): void
    {
        foreach ($ids as $id) {
            $this->runQuietly(['docker', 'rm', '-f', $id], 30);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function removeNetworks(array $ids): void
    {
        foreach ($ids as $id) {
            $this->runQuietly(['docker', 'network', 'rm', $id], 30);
        }
    }

    /**
     * @param  list<string>  $cmd
     * @return list<string>
     */
    private function listIds(array $cmd): array
    {
        return $this->runLines($cmd);
    }

    /**
     * Run a docker query and return its non-empty output lines. Best-effort:
     * a docker failure (socket gone, in tests) yields an empty list.
     *
     * @param  list<string>  $cmd
     * @return list<string>
     */
    private function runLines(array $cmd): array
    {
        try {
            $process = $this->newProcess($cmd);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            return array_values(array_filter(
                array_map('trim', explode("\n", $process->getOutput())),
                static fn (string $line): bool => $line !== '',
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $cmd
     */
    private function runQuietly(array $cmd, int $timeout): void
    {
        try {
            $process = $this->newProcess($cmd);
            $process->setTimeout($timeout);
            $process->run();
        } catch (Throwable) {
            // best-effort — resource already gone, or docker unavailable
        }
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}
