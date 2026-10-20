<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /threads/{id}/approvals/{approvalId} — answer a permission request. */
class ResolveApproval extends DaemonRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly string $threadId, private readonly string $approvalId, array $payload)
    {
        parent::__construct($payload);
    }

    public function resolveEndpoint(): string
    {
        return '/threads/'.rawurlencode($this->threadId).'/approvals/'.rawurlencode($this->approvalId);
    }
}
