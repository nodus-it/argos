<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repos/{owner}/{project}/hooks — register an issues/issue_comment webhook.
 */
class RegisterWebhook extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly string $callbackUrl,
        private readonly string $secret,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->project}/hooks";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'name' => 'web',
            'active' => true,
            'events' => ['issues', 'issue_comment'],
            'config' => [
                'url' => $this->callbackUrl,
                'secret' => $this->secret,
                'content_type' => 'json',
                'insecure_ssl' => '0',
            ],
        ];
    }
}
