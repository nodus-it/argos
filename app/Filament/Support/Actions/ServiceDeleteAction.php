<?php

declare(strict_types=1);

namespace App\Filament\Support\Actions;

use App\Services\EntityService;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

/** Filament's delete action, routed through the entity's service (no model writes in the UI). */
final class ServiceDeleteAction
{
    /** @param  class-string<EntityService>  $service */
    public static function make(string $service): DeleteAction
    {
        return DeleteAction::make()->using(function (Model $record) use ($service): bool {
            app($service)->delete($record);

            return true;
        });
    }
}
