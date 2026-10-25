<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\Shared\WorkspaceForm;
use App\Models\User;
use App\Services\EntityService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Database\Eloquent\Model;

/** Saving a new workspace, shared by the guided and the expert page. */
trait CreatesWorkspace
{
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return WorkspaceForm::dehydrate($data);
    }

    protected function getRedirectUrl(): string
    {
        return WorkspaceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
