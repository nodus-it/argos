<?php

declare(strict_types=1);

namespace App\Console\Commands;

class AgentDiffCommand extends AgentPhaseCommand
{
    protected $signature = 'agent:diff {task : Task-Name}';

    protected $description = 'Diff-Phase synchron ausführen (live Output)';

    protected function phase(): string
    {
        return 'diff';
    }
}
