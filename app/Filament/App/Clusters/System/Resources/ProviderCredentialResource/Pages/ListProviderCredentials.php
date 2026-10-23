<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources\ProviderCredentialResource\Pages;

use App\Filament\App\Clusters\System\Resources\ProviderCredentialResource;
use App\Support\DocsLinkAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProviderCredentials extends ListRecords
{
    protected static string $resource = ProviderCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('credentials'),
            CreateAction::make(),
        ];
    }
}
