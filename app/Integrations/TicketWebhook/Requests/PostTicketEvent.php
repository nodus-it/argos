<?php

declare(strict_types=1);

namespace App\Integrations\TicketWebhook\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasStringBody;

/** POST <callback URL> — one signed task event (X-Argos-Event, X-Argos-Signature). */
class PostTicketEvent extends Request implements HasBody
{
    use HasStringBody;

    protected Method $method = Method::POST;

    public function __construct(string $body, private readonly string $event, private readonly string $signature)
    {
        $this->body()->set($body);
    }

    public function resolveEndpoint(): string
    {
        return '';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Argos-Event' => $this->event,
            'X-Argos-Signature' => $this->signature,
        ];
    }
}
