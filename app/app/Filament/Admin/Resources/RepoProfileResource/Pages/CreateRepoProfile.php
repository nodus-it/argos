<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepoProfile extends CreateRecord
{
    protected static string $resource = RepoProfileResource::class;
}
