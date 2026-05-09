<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Resources\WorkerStackResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkerStack extends ViewRecord
{
    protected static string $resource = WorkerStackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => ! $this->record->is_builtin),
            WorkerStackResource::duplicateAction(),
        ];
    }
}
