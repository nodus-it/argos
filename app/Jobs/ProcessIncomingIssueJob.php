<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\IssueTracker\IssueIngestService;
use App\Services\IssueTracker\IssueTrackerRegistry;
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
     * @param  array<string, mixed>  $envelope  Raw webhook envelope from the provider
     * @param  string|null  $eventType  Provider-specific event type header value
     */
    public function __construct(
        private readonly string $bindingId,
        private readonly array $envelope,
        private readonly ?string $eventType = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(IssueTrackerRegistry $registry, IssueIngestService $ingestService): void
    {
        $binding = TaskProviderBinding::find($this->bindingId);

        if (! $binding instanceof TaskProviderBinding) {
            return;
        }

        if (! $registry->has($binding->kind)) {
            return;
        }

        $tracker = $registry->make($binding->kind, $binding);
        $issue = $tracker->normalizeWebhookPayload($this->envelope, $this->eventType);

        if (empty($issue)) {
            return;
        }

        $ingestService->ingest(ExternalIssue::fromProvider($issue, $binding->kind), $binding);
    }
}
