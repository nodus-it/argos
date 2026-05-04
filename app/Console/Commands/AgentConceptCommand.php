<?php

declare(strict_types=1);

namespace App\Console\Commands;

class AgentConceptCommand extends AgentPhaseCommand
{
    protected $signature = 'agent:concept {task : Task-Name}';

    protected $description = 'Concept-Phase synchron ausführen (live Output)';

    protected function phase(): string
    {
        return 'concept';
    }
}
