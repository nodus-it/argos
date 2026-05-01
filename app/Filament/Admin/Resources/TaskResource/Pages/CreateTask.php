<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Resources\TaskResource;
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

        $configDir = config('argos.config_dir');
        $descriptionDir = "{$configDir}/tasks/{$record->name}";

        if (!is_dir($descriptionDir)) {
            mkdir($descriptionDir, 0755, true);
        }

        $descriptionPath = "{$descriptionDir}/description.md";
        file_put_contents($descriptionPath, $data['description'] ?? '');

        $volumeName = "task_ws_{$record->name}";
        $process = Process::fromShellCommandline("docker volume create {$volumeName}");
        $process->run();

        return $record;
    }
}
