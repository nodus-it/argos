<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkerStackResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\WorkerStackResource;
use App\Models\WorkerStack;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail for built-in worker stacks (which cannot be edited).
 * Uses the same styled layout as the edit form; user stacks open edit
 * directly (see WorkerStackResource::table() recordUrl).
 *
 * @property-read WorkerStack $record
 */
class ViewWorkerStack extends ViewRecord
{
    use HasArgosEditHeading;

    protected static string $resource = WorkerStackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => ! $this->record->is_builtin),
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
}
