<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\StateReader;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function mount(): void
    {
        parent::mount();
        $this->syncRunning();
    }

    public function hydrate(): void
    {
        $this->syncRunning();
    }

    private function syncRunning(): void
    {
        $reader = app(StateReader::class);

        Task::where('current_status', 'running')
            ->get()
            ->each(fn (Task $task) => $reader->syncToDb($task));
    }
}
