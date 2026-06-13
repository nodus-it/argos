<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Resources\WorkerStackResource;
use App\Support\DocsLinkAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkerStacks extends ListRecords
{
    protected static string $resource = WorkerStackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('worker-stacks'),
            CreateAction::make(),
        ];
    }
}
