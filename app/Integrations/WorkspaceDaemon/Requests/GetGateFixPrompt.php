<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /gates/fix-prompt — the worker's fix prompt for one failed gate. */
class GetGateFixPrompt extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $gate, private readonly string $runId) {}

    public function resolveEndpoint(): string
    {
        return '/gates/fix-prompt';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['gate' => $this->gate, 'run_id' => $this->runId];
    }
}
