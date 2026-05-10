<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Resources\TaskResource;
use App\Services\Task\TaskService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

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
