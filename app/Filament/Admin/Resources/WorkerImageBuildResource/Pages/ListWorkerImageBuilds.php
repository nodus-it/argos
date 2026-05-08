<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerImageBuildResource\Pages;

use App\Filament\Admin\Resources\WorkerImageBuildResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkerImageBuilds extends ListRecords
{
    protected static string $resource = WorkerImageBuildResource::class;
}
