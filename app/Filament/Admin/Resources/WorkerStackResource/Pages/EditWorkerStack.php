<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\WorkerStackResource;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Filament\Admin\Support\WorkerStackBuildDispatcher;
use App\Models\WorkerStack;
use App\Services\EntityService;
use App\Services\Worker\WorkerStackService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * @property-read WorkerStack $record
 */
class EditWorkerStack extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = WorkerStackResource::class;

    protected function service(): EntityService
    {
        return app(WorkerStackService::class);
    }

    private ?string $dockerfileBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (): bool => ! $this->record->is_builtin),
        ];
    }

    protected function argosHeadingAttribute(): string
    {
        return 'label';
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        return $this->record->is_builtin
            ? ['icon' => 'heroicon-o-shield-check', 'label' => __('worker.stacks.fields.is_builtin')]
            : null;
    }

    protected function beforeSave(): void
    {
        // Snapshot the dockerfile_body before fillForm-data is persisted so
        // afterSave() can compare and only dispatch a rebuild when the body
        // actually changed (label-only edits don't need a rebuild).
        /** @var WorkerStack $stack */
        $stack = $this->record;
        $this->dockerfileBeforeSave = $stack->getOriginal('dockerfile_body');
    }

    /**
     * Auto-dispatch build jobs when the dockerfile content changed.
     * Other field edits (label, capabilities, etc.) don't require a
     * rebuild — the resolver tag is hashed off dockerfile_body only,
     * so an unchanged body means the existing image stays valid.
     */
    protected function afterSave(): void
    {
        /** @var WorkerStack $stack */
        $stack = $this->record;

        if ($stack->dockerfile_body === $this->dockerfileBeforeSave) {
            return;
        }

        $dispatched = app(WorkerStackBuildDispatcher::class)->dispatchForStack($stack);

        if ($dispatched > 0) {
            Notification::make()
                ->title(__('worker.stacks.notifications.build_dispatched', ['count' => $dispatched]))
                ->success()
                ->send();
        }
    }
}
