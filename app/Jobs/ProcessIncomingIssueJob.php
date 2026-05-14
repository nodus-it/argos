<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueIngestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessIncomingIssueJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $issue  Raw issue payload from the provider
     */
    public function __construct(
        private readonly string $bindingId,
        private readonly array $issue,
    ) {
        $this->onQueue('default');
    }

    public function handle(IssueIngestService $ingestService): void
    {
        $binding = TaskProviderBinding::find($this->bindingId);

        if (! $binding instanceof TaskProviderBinding) {
            return;
        }

        $ingestService->ingest($this->issue, $binding);
    }
}
