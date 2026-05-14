<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\TaskProviderKind;
use App\Jobs\ProcessIncomingIssueJob;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class IssueWebhookController extends Controller
{
    public function __construct(private readonly IssueTrackerRegistry $registry) {}

    public function handle(Request $request, string $kind, string $binding): Response
    {
        $providerKind = TaskProviderKind::tryFrom($kind);

        if ($providerKind === null) {
            return response('Unknown provider', 400);
        }

        try {
            $taskProviderBinding = TaskProviderBinding::findOrFail($binding);
        } catch (ModelNotFoundException) {
            return response('Not found', 404);
        }

        // For GitHub, filter out non-issue events early so GitHub doesn't retry
        $eventType = $this->extractEventType($request, $providerKind);
        if ($providerKind === TaskProviderKind::GitHub && ! $this->isRelevantGitHubEvent($eventType)) {
            return response('ignored', 200);
        }

        $payload = $request->getContent();
        $secret = $taskProviderBinding->webhook_secret;

        if ($secret === null || $secret === '') {
            Log::channel('argos')->warning('IssueWebhook: no webhook_secret configured', [
                'binding_id' => $taskProviderBinding->id,
            ]);

            return response('Unauthorized', 401);
        }

        if (! $this->registry->has($providerKind)) {
            return response('Provider not registered', 400);
        }

        $tracker = $this->registry->make($providerKind, $taskProviderBinding);
        $signature = $this->extractSignature($request, $providerKind);

        if (! $tracker->verifySignature($payload, $signature, $secret)) {
            Log::channel('argos')->warning('IssueWebhook: invalid signature', [
                'binding_id' => $taskProviderBinding->id,
                'kind' => $kind,
            ]);

            return response('Unauthorized', 401);
        }

        // Idempotency: skip duplicate deliveries using a cache lock on delivery ID.
        $deliveryId = $this->extractDeliveryId($request, $providerKind);
        if ($deliveryId !== null) {
            $cacheKey = "webhook_delivery:{$deliveryId}";
            if (! Cache::add($cacheKey, 1, now()->addHours(24))) {
                return response('Already processed', 200);
            }
        }

        $issue = $request->json()->all();

        ProcessIncomingIssueJob::dispatch($taskProviderBinding->id, $issue, $eventType);

        return response('', 200);
    }

    private function extractSignature(Request $request, TaskProviderKind $kind): string
    {
        return match ($kind) {
            TaskProviderKind::GitHub => (string) $request->header('X-Hub-Signature-256', ''),
            TaskProviderKind::GitLab => (string) $request->header('X-Gitlab-Token', ''),
            default => '',
        };
    }

    private function extractDeliveryId(Request $request, TaskProviderKind $kind): ?string
    {
        return match ($kind) {
            TaskProviderKind::GitHub => $request->header('X-GitHub-Delivery'),
            TaskProviderKind::GitLab => $request->header('X-Gitlab-Event-UUID'),
            default => null,
        };
    }

    private function extractEventType(Request $request, TaskProviderKind $kind): ?string
    {
        return match ($kind) {
            TaskProviderKind::GitHub => $request->header('X-GitHub-Event'),
            default => null,
        };
    }

    private function isRelevantGitHubEvent(?string $eventType): bool
    {
        return in_array($eventType, ['issues', 'issue_comment'], true);
    }
}
