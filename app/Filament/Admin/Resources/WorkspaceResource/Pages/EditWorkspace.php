<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkspaceResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\WorkspaceResource;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Filament\Shared\WorkspaceForm;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntityService;
use App\Services\Workspace\WorkspaceService;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

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
     * Pre-select the OAuth pickers from the persisted repo_url + default
     * branch and add the hidden repository token.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Workspace $record */
        $record = $this->getRecord();

        return WorkspaceForm::fillFromRecord($record, $data);
    }

    /**
     * OAuth pickers → repo_url / default_branch, auto settings cleaned.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return WorkspaceForm::dehydrate($data);
    }

    /**
     * Through WorkspaceService::updateSettings (same path as the /w
     * settings page).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Workspace $record */
        /** @var User $user */
        $user = auth()->user();

        return app(WorkspaceService::class)->updateSettings($record, $user, $data);
    }
}
