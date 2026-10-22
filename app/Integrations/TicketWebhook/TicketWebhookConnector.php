<?php

declare(strict_types=1);

namespace App\Integrations\TicketWebhook;

use App\Support\OutboundUrlGuard;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

/**
 * Saloon connector for an own ticket system's webhook (the URL configured on
 * its integration). Pure transport: the payload and signature come from
 * SendTicketWebhookJob.
 */
class TicketWebhookConnector extends Connector
{
    use AlwaysThrowOnErrors;

    public function __construct(private readonly string $url) {}

    public function resolveBaseUrl(): string
    {
        return $this->url;
    }

    /** The URL is configured by people: never let it reach Argos' own services. */
    public function boot(PendingRequest $pendingRequest): void
    {
        OutboundUrlGuard::assertAllowed($pendingRequest->getUrl());
    }

    /** @return array<string, int> */
    protected function defaultConfig(): array
    {
        return ['timeout' => 10];
    }
}
