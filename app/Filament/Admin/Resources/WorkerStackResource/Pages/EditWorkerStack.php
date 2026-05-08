<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Resources\WorkerStackResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkerStack extends EditRecord
{
    protected static string $resource = WorkerStackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (): bool => ! $this->record->is_builtin),
        ];
    }
}
