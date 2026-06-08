<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * DELETE /repos/{owner}/{project}/hooks/{webhookId} — remove a webhook.
 */
class UnregisterWebhook extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $webhookId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->project}/hooks/{$this->webhookId}";
    }
}
