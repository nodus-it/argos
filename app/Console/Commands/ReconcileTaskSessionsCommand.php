<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Workspace\ReconcileTaskSessionsJob;
use Illuminate\Console\Command;

class ReconcileTaskSessionsCommand extends Command
{
    protected $signature = 'argos:workspaces:reconcile-sessions';

    protected $description = 'Close task sessions their workspace daemon no longer knows (dispatches to the queue).';

    public function handle(): int
    {
        ReconcileTaskSessionsJob::dispatch();
        $this->info('Dispatched the session reconcile to the queue.');

        return self::SUCCESS;
    }
}
