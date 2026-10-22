<?php

declare(strict_types=1);

namespace App\Filament\Support\Actions;

use App\Services\EntityService;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Filament's bulk delete, routed through the entity's service one record at a time. */
final class ServiceDeleteBulkAction
{
    /** @param  class-string<EntityService>  $service */
    public static function make(string $service): DeleteBulkAction
    {
        return DeleteBulkAction::make()->action(function (Collection $records) use ($service): void {
            $records->each(fn (Model $record) => app($service)->delete($record));
        });
    }
}
