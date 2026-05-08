<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workers\Builtin\BuiltinSync;
use Illuminate\Console\Command;

class SyncBuiltinWorkerImages extends Command
{
    protected $signature = 'argos:sync-builtin-images
        {--dry-run : Show what would change without writing to the DB}';

    protected $description = 'Mirror the built-in worker stack manifest into the DB.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('argos:sync-builtin-images — dry-run (no DB writes).');
        }

        $summary = BuiltinSync::default()->sync($dryRun);

        $this->table(
            ['Entity', 'Created', 'Updated', 'Deprecated', 'Unchanged'],
            [
                ['stacks', $summary['created'], $summary['updated'], $summary['deprecated'], $summary['unchanged']],
            ],
        );

        return self::SUCCESS;
    }
}
