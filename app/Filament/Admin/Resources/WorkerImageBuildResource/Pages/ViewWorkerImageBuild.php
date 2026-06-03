<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerImageBuildResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\WorkerImageBuildResource;
use App\Jobs\BuildWorkerImageJob;
use App\Models\WorkerImageBuild;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkerImageBuild extends ViewRecord
{
    use HasArgosEditHeading;

    protected static string $resource = WorkerImageBuildResource::class;

    protected function argosHeadingAttribute(): string
    {
        return 'tag';
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        /** @var WorkerImageBuild $record */
        $record = $this->getRecord();

        return ['icon' => 'heroicon-o-cube', 'label' => $record->status->value];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuild')
                ->label(__('worker.image_builds.actions.rebuild'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var WorkerImageBuild $record */
                    $record = $this->record;
                    BuildWorkerImageJob::dispatch($record->worker_stack_id, $record->agent_name);
                    Notification::make()
                        ->success()
                        ->title(__('worker.image_builds.actions.rebuild_dispatched'))
                        ->send();
                }),
        ];
    }
}
