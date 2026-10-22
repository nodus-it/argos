<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Resources\WorkspaceResource\Pages;

use App\Filament\Support\Pages\CreateRecord;
use App\Filament\Shared\WorkspaceForm;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Models\User;
use App\Services\EntityService;
use App\Services\Workspace\WorkspaceService;
use App\Support\DocsLinkAction;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * New workspace (Argos admins only — the resource's `create` ability): the
 * shared workspace form, platform and repository first. Opens the new
 * workspace afterwards.
 */
class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected static bool $canCreateAnother = false;

    protected function service(): EntityService
    {
        return app(WorkspaceService::class);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([WorkspaceForm::tabs()]);
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

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('projects'),
        ];
    }
}
