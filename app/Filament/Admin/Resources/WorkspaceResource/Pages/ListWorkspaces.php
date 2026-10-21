<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkspaceResource\Pages;

use App\Filament\Admin\Resources\WorkspaceResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\View\View;

class ListWorkspaces extends ListRecords
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeader(): ?View
    {
        return view('filament.admin.heros.projects-list-hero');
    }
}
