<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AgentCredentialResource\Pages;

use App\Filament\Admin\Resources\AgentCredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentCredentials extends ListRecords
{
    protected static string $resource = AgentCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
