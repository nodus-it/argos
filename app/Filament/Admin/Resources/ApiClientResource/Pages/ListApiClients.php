<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiClientResource\Pages;

use App\Filament\Admin\Resources\ApiClientResource;
use App\Support\DocsLinkAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApiClients extends ListRecords
{
    protected static string $resource = ApiClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('rest-api'),
            CreateAction::make(),
        ];
    }
}
