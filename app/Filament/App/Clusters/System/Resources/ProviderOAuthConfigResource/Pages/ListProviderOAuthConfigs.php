<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources\ProviderOAuthConfigResource\Pages;

use App\Filament\App\Clusters\System\Resources\ProviderOAuthConfigResource;
use App\Support\DocsLinkAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProviderOAuthConfigs extends ListRecords
{
    protected static string $resource = ProviderOAuthConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('oauth'),
            CreateAction::make(),
        ];
    }
}
