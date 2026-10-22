<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages;

use App\Filament\App\Clusters\System\Resources\ApiClientResource;
use App\Filament\Support\Pages\CreateRecord;
use App\Services\Api\ApiClientService;
use App\Services\EntityService;

class CreateApiClient extends CreateRecord
{
    protected static string $resource = ApiClientResource::class;

    protected function service(): EntityService
    {
        return app(ApiClientService::class);
    }
}
