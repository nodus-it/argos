<?php

declare(strict_types=1);

namespace App\Console\Commands;

class AgentImplementCommand extends AgentPhaseCommand
{
    protected $signature = 'agent:implement {task : Task-Name}';

    protected $description = 'Implement-Phase synchron ausführen (live Output)';

    protected function phase(): string
    {
        return 'implement';
    }
}
