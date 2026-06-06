<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Concerns\TaskTableConcern;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use App\Services\Workflow\StateReader;
use Filament\Resources\Pages\ListRecords;
use Illuminate\View\View;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeader(): ?View
    {
        return view('filament.admin.heros.tasks-list-hero');
    }

    public function getTabs(): array
    {
        return TaskTableConcern::taskTableTabs();
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
