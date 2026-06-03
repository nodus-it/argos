<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiClientResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\ApiClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApiClient extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
