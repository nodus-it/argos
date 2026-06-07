<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * DELETE /projects/{path}/hooks/{webhookId} — remove a webhook.
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
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/hooks/{$this->webhookId}";
    }
}
