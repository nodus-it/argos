<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRepoProfile extends EditRecord
{
    protected static string $resource = RepoProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Derive the shared oauth_repo / oauth_branch picker values from the
     * persisted url + default_branch so the OAuth picker shows the current
     * values when the form opens. Also ensures auth_method has a default.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! isset($data['auth_method']) || $data['auth_method'] === '') {
            $data['auth_method'] = 'pat';
        }

        $url = $data['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $repoPath = RepoProfileResource::repoPathFromUrl($url);
            if ($repoPath !== null) {
                $data['oauth_repo'] = $repoPath;
            }
        }

        if (isset($data['default_branch']) && is_string($data['default_branch'])) {
            $data['oauth_branch'] = $data['default_branch'];
        }

        return $data;
    }

    /**
     * Im OAuth-Pfad schreibt der sichtbare Picker in `oauth_repo` /
     * `oauth_branch`; die DB-Spalten sind `url` / `default_branch`. Mappen
     * und Helper-Keys vor dem Save entfernen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return RepoProfileResource::mutateOauthFields($data);
    }
}
