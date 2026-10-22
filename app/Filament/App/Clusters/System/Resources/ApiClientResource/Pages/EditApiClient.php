<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages;

use App\Filament\App\Clusters\System\Resources\ApiClientResource;
use App\Filament\Concerns\HasArgosEditHeading;
use App\Filament\Support\Actions\ServiceDeleteAction;
use App\Filament\Support\Pages\EditRecord;
use App\Services\Api\ApiClientService;
use App\Services\EntityService;

class EditApiClient extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = ApiClientResource::class;

    protected function service(): EntityService
    {
        return app(ApiClientService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            ServiceDeleteAction::make(ApiClientService::class),
        ];
    }
}
