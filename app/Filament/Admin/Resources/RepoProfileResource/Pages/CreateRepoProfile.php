<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use App\Filament\Admin\Support\Pages\CreateRecord;
use App\Services\EntityService;
use App\Services\Project\RepoProfileService;

class CreateRepoProfile extends CreateRecord
{
    protected static string $resource = RepoProfileResource::class;

    protected function service(): EntityService
    {
        return app(RepoProfileService::class);
    }

    /**
     * Im OAuth-Pfad schreibt der sichtbare Picker in `oauth_repo` /
     * `oauth_branch`; die DB-Spalten sind `url` / `default_branch`. Mappen
     * und Helper-Keys vor dem Insert entfernen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RepoProfileResource::mutateOauthFields($data);
    }
}
