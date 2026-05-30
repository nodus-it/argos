<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueIngestService;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PollIssueProviderJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(private readonly string $bindingId)
    {
        $this->onQueue('default');
    }

    public function handle(IssueTrackerRegistry $registry, IssueIngestService $ingestService): void
    {
        $binding = TaskProviderBinding::with('connectedAccount')->find($this->bindingId);

        if (! $binding instanceof TaskProviderBinding) {
            return;
        }

        if ($binding->mode !== TaskProviderMode::Poll) {
            return;
        }

        if ($binding->sync_status !== TaskProviderSyncStatus::Active) {
            return;
        }

        if (! $registry->has($binding->kind)) {
            return;
        }

        try {
            $tracker = $registry->make($binding->kind, $binding);
            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');

            $filters = $binding->filters ?? [];
            $issues = $tracker->listIssues($owner, $project, $filters);

            foreach ($issues as $issue) {
                $ingestService->ingest($issue, $binding);
            }

            $binding->last_polled_at = now();
            $binding->last_error = null;
            $binding->save();
        } catch (\Throwable $e) {
            Log::channel('argos')->error('PollIssueProviderJob: polling failed', [
                'binding_id' => $this->bindingId,
                'error' => $e->getMessage(),
            ]);

            $binding->last_error = $e->getMessage();
            $binding->save();
        }
    }

    /**
     * @return array{string, string}
     */
    private function parseRef(string $ref): array
    {
        $parts = explode('/', $ref, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
