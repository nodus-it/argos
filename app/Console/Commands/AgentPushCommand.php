<?php

declare(strict_types=1);

namespace App\Console\Commands;

class AgentPushCommand extends AgentPhaseCommand
{
    protected $signature = 'agent:push {task : Task-Name}';

    protected $description = 'Push-Phase synchron ausführen (live Output)';

    protected function phase(): string
    {
        return 'push';
    }
}
