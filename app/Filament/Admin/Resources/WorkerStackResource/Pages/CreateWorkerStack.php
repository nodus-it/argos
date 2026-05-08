<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Resources\WorkerStackResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkerStack extends CreateRecord
{
    protected static string $resource = WorkerStackResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // User-created stacks are never built-in. Sync controls is_builtin
        // for the rows it owns; the UI never sets it.
        $data['is_builtin'] = false;

        return $data;
    }
}
