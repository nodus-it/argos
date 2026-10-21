<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /tasks/{id}/start — open an interactive session. */
class StartTask extends DaemonRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly string $taskId, array $payload = [])
    {
        parent::__construct($payload);
    }

    public function resolveEndpoint(): string
    {
        return '/tasks/'.rawurlencode($this->taskId).'/start';
    }
}
