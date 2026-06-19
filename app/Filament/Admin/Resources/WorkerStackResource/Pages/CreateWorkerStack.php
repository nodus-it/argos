<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Resources\WorkerStackResource;
use App\Filament\Admin\Support\Pages\CreateRecord;
use App\Filament\Admin\Support\WorkerStackBuildDispatcher;
use App\Models\WorkerStack;
use App\Services\EntityService;
use App\Services\Worker\WorkerStackService;
use Filament\Notifications\Notification;

class CreateWorkerStack extends CreateRecord
{
    protected static string $resource = WorkerStackResource::class;

    protected function service(): EntityService
    {
        return app(WorkerStackService::class);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // User-created stacks are never built-in. Sync controls is_builtin
        // for the rows it owns; the UI never sets it.
        $data['is_builtin'] = false;

        return $data;
    }

    /**
     * Auto-dispatch build jobs for the new stack so the user gets fast
     * "did my dockerfile build?" feedback instead of waiting until the
     * next phase run. Skipped when no compatible agents are registered.
     */
    protected function afterCreate(): void
    {
        /** @var WorkerStack $stack */
        $stack = $this->record;

        $dispatched = app(WorkerStackBuildDispatcher::class)->dispatchForStack($stack);

        if ($dispatched > 0) {
            Notification::make()
                ->title(__('worker.stacks.notifications.build_dispatched', ['count' => $dispatched]))
                ->success()
                ->send();
        }
    }
}
