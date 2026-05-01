<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Phase\StateReader;
use App\Models\Task;
use Illuminate\Console\Command;

abstract class AgentPhaseCommand extends Command
{
    abstract protected function phase(): string;

    public function handle(PhaseRunner $runner, StateReader $stateReader): int
    {
        $taskName = $this->argument('task');

        $task = Task::where('name', $taskName)->first();
        if ($task === null) {
            $this->error("Task '{$taskName}' nicht gefunden.");
            return self::FAILURE;
        }

        if ($task->phaseRuns()->where('status', 'running')->exists()) {
            $this->error('Eine Phase läuft bereits.');
            return self::FAILURE;
        }

        $exitCode = $runner->runLive(
            $task,
            $this->phase(),
            fn (string $chunk) => $this->output->write($chunk),
        );

        $task->refresh();
        $stateReader->syncToDb($task);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
