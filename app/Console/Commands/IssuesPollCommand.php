<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Jobs\PollIssueProviderJob;
use App\Models\TaskProviderBinding;
use Illuminate\Console\Command;

final class IssuesPollCommand extends Command
{
    protected $signature = 'argos:poll-issues';

    protected $description = 'Dispatch polling jobs for all active Poll-mode task-provider bindings';

    public function handle(): int
    {
        $bindings = TaskProviderBinding::query()
            ->where('mode', TaskProviderMode::Poll)
            ->where('sync_status', TaskProviderSyncStatus::Active)
            ->get();

        foreach ($bindings as $binding) {
            PollIssueProviderJob::dispatch($binding->id);
        }

        $this->info("Dispatched {$bindings->count()} poll job(s).");

        return self::SUCCESS;
    }
}
