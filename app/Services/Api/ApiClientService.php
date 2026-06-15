<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiClient;
use App\Services\EntityService;

/**
 * Operations on API clients (named, full-access API token holders). Plain CRUD
 * via the base today.
 */
class ApiClientService extends EntityService
{
    protected function model(): string
    {
        return ApiClient::class;
    }
}
