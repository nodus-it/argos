<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Workspace\ReapOrphanedWorkspacesJob;
use Illuminate\Console\Command;

class ReapOrphanedWorkspacesCommand extends Command
{
    protected $signature = 'argos:workspaces:reap-orphans';

    protected $description = 'Remove workspace containers whose workspace no longer exists (dispatches to the queue).';

    public function handle(): int
    {
        ReapOrphanedWorkspacesJob::dispatch();
        $this->info('Dispatched orphaned-workspace sweep to the queue.');

        return self::SUCCESS;
    }
}
