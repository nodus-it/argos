<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Models\AgentCredential;
use App\Services\EntityService;

/**
 * Operations on a stored agent credential (the Claude/Codex auth the worker
 * runs with). Plain CRUD via the base today; form-side input normalisation and
 * the live token probe still run in the resource's save hooks.
 */
class AgentCredentialService extends EntityService
{
    protected function model(): string
    {
        return AgentCredential::class;
    }
}
