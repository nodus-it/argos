<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\View\View;

class ListRepoProfiles extends ListRecords
{
    protected static string $resource = RepoProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeader(): ?View
    {
        return view('filament.admin.heros.projects-list-hero');
    }
}
