<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /projects/{path}/hooks — register an issues webhook.
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
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/hooks";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'url' => $this->callbackUrl,
            'token' => $this->secret,
            'issues_events' => true,
            'confidential_issues_events' => true,
            'note_events' => false,
            'enable_ssl_verification' => true,
        ];
    }
}
