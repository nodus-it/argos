<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Resources\TaskResource;
use App\Services\Task\TaskService;
use App\Support\DocsLinkAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('tasks'),
        ];
    }

    /**
     * Pre-select the project when opened from a project's "Neuer Task" button
     * (?repo_profile_id=…). RepoProfile keys are ULIDs, so read as a string.
     */
    protected function fillForm(): void
    {
        $repoProfileId = request()->query('repo_profile_id');

        $this->callHook('beforeFill');
        $this->form->fill(
            (is_string($repoProfileId) && $repoProfileId !== '')
                ? ['repo_profile_id' => $repoProfileId]
                : []
        );
        $this->callHook('afterFill');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['user_id'] = auth()->id();

        try {
            return app(TaskService::class)->createTask($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'data.name' => [__('validation.unique', ['attribute' => 'name'])],
            ]);
        }
    }
}
