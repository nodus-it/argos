<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiClientResource\Pages;

use App\Filament\Admin\Resources\ApiClientResource;
use App\Filament\Admin\Support\Pages\CreateRecord;
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
