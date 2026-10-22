<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters\System\Resources\ApiClientResource\Pages;

use App\Filament\Concerns\HasArgosEditHeading;
use App\Filament\Workspaces\Clusters\System\Resources\ApiClientResource;
use App\Filament\Support\Pages\EditRecord;
use App\Services\Api\ApiClientService;
use App\Services\EntityService;
use Filament\Actions\DeleteAction;

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
            DeleteAction::make(),
        ];
    }
}
