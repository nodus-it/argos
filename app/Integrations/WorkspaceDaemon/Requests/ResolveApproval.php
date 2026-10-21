<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /tasks/{id}/approvals/{approvalId} — answer a permission request. */
class ResolveApproval extends DaemonRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly string $taskId, private readonly string $approvalId, array $payload)
    {
        parent::__construct($payload);
    }

    public function resolveEndpoint(): string
    {
        return '/tasks/'.rawurlencode($this->taskId).'/approvals/'.rawurlencode($this->approvalId);
    }
}
