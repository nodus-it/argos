<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages;

use App\Filament\Admin\Resources\ProviderOAuthConfigResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProviderOAuthConfig extends EditRecord
{
    protected static string $resource = ProviderOAuthConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
