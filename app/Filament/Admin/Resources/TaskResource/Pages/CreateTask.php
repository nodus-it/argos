<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['user_id'] = auth()->id();

        /** @var Task $record */
        $record = parent::handleRecordCreation($data);
        try {
            /** @var Task $record */
            $record = parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'data.name' => [__('validation.unique', ['attribute' => 'name'])],
            ]);
        }

        Process::fromShellCommandline('docker volume create '.escapeshellarg($record->volumeName()))->run();

        if ($record->auto_concept) {
            $record->update([
                'workflow_status' => WorkflowStatus::ConceptRunning,
                'current_phase' => 'concept',
                'current_status' => 'pending',
            ]);
            RunPhaseJob::dispatch($record->id, 'concept');
        }

        return $record;
    }
}
