<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters\System\Resources\UserResource\Pages;

use App\Filament\Workspaces\Clusters\System\Resources\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
