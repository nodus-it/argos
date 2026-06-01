<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderCredentialResource\Pages;

use App\Filament\Admin\Resources\ProviderCredentialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProviderCredential extends EditRecord
{
    protected static string $resource = ProviderCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
