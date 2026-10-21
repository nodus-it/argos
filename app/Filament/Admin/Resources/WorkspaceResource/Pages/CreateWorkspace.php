<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkspaceResource\Pages;

use App\Filament\Admin\Resources\WorkspaceResource;
use App\Filament\Admin\Support\Pages\CreateRecord;
use App\Models\User;
use App\Services\EntityService;
use App\Services\Workspace\WorkspaceService;
use App\Support\DocsLinkAction;
use Illuminate\Database\Eloquent\Model;

class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function service(): EntityService
    {
        return app(WorkspaceService::class);
    }

    /**
     * Through WorkspaceService: slug, container identity and the creator as
     * first admin.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return app(WorkspaceService::class)->createWorkspace($user, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('projects'),
        ];
    }

    /**
     * Im OAuth-Pfad schreibt der sichtbare Picker in `oauth_repo` /
     * `oauth_branch`; die DB-Spalten sind `repo_url` / `default_branch`. Mappen
     * und Helper-Keys vor dem Insert entfernen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return WorkspaceResource::mutateAutoSettings(WorkspaceResource::mutateOauthFields($data));
    }
}
