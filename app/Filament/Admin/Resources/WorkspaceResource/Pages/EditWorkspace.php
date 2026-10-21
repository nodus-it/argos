<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkspaceResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\WorkspaceResource;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Models\Workspace;
use App\Services\EntityService;
use App\Services\Workspace\WorkspaceService;
use Filament\Actions\DeleteAction;

class EditWorkspace extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = WorkspaceResource::class;

    protected function service(): EntityService
    {
        return app(WorkspaceService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        /** @var Workspace $record */
        $record = $this->getRecord();
        $platform = $record->platform?->value;

        return $platform === null
            ? null
            : ['icon' => 'heroicon-o-globe-alt', 'label' => __('projects.fields.platform_'.$platform)];
    }

    /**
     * Derive the shared oauth_repo / oauth_branch picker values from the
     * persisted repo_url + default_branch so the OAuth picker shows the current
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

        // The token is $hidden on the model (kept out of serialisation), so
        // the default fill misses it.
        /** @var Workspace $record */
        $record = $this->getRecord();
        $data['token'] = $record->token;

        $url = $data['repo_url'] ?? null;
        if (is_string($url) && $url !== '') {
            $repoPath = WorkspaceResource::repoPathFromUrl($url);
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
     * `oauth_branch`; die DB-Spalten sind `repo_url` / `default_branch`. Mappen
     * und Helper-Keys vor dem Save entfernen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return WorkspaceResource::mutateAutoSettings(WorkspaceResource::mutateOauthFields($data));
    }
}
