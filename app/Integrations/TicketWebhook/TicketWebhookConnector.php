<?php

declare(strict_types=1);

namespace App\Integrations\TicketWebhook;

use Saloon\Http\Connector;
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

    /** @return array<string, int> */
    protected function defaultConfig(): array
    {
        return ['timeout' => 10];
    }
}
