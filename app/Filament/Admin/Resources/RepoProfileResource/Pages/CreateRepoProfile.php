<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepoProfile extends CreateRecord
{
    protected static string $resource = RepoProfileResource::class;

    /**
     * Im OAuth-Pfad schreiben die sichtbaren Picker in `github_repo` /
     * `github_branch`; die DB-Spalten sind `url` / `default_branch`. Mappen
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
