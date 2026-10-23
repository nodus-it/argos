<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages;

use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource;
use App\Support\DocsLinkAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentCredentials extends ListRecords
{
    protected static string $resource = AgentCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('agents'),
            CreateAction::make(),
        ];
    }
}
