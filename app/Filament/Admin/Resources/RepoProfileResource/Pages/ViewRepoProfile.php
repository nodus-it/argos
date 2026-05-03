<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRepoProfile extends ViewRecord
{
    protected static string $resource = RepoProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
