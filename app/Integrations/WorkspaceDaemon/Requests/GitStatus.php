<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /git/status — branch, ahead/behind and changed files. */
class GitStatus extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/git/status';
    }
}
