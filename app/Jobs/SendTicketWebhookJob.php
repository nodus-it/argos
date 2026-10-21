<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\TicketWebhook\Requests\PostTicketEvent;
use App\Integrations\TicketWebhook\TicketWebhookConnector;
use App\Models\TaskProviderBinding;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells an own ticket system what happened to a task: a JSON POST to the
 * integration's callback URL, signed with its secret
 * (`X-Argos-Signature: sha256=<hmac of the body>`). Retried a few times;
 * failures are only logged.
 */
class SendTicketWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $bindingId, public readonly array $payload) {}

    public function handle(): void
    {
        $binding = TaskProviderBinding::query()->find($this->bindingId);
        if ($binding === null || $binding->callback_url === null || $binding->callback_url === '') {
            return;
        }

        $body = (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, (string) $binding->webhook_secret);

        try {
            (new TicketWebhookConnector($binding->callback_url))->send(new PostTicketEvent($body, (string) ($this->payload['event'] ?? ''), $signature));
        } catch (Throwable $e) {
            Log::channel('argos')->info('Ticket webhook failed', ['binding' => $binding->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
