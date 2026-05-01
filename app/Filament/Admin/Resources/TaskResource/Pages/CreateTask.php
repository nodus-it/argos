<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Process\Process;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Task $record */
        $record = parent::handleRecordCreation($data);

        Process::fromShellCommandline('docker volume create '.escapeshellarg($record->volumeName()))->run();

        if ($record->auto_concept) {
            $record->update(['workflow_status' => WorkflowStatus::ConceptRunning]);
            RunPhaseJob::dispatch($record->id, 'concept');
        }

        return $record;
    }
}
