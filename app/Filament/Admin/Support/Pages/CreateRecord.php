<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support\Pages;

use App\Services\EntityService;
use Filament\Resources\Pages\CreateRecord as FilamentCreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Base create page that routes persistence through the resource's entity
 * service, so the write lives in a service and the page keeps only its
 * form/validation/redirect concerns.
 */
abstract class CreateRecord extends FilamentCreateRecord
{
    abstract protected function service(): EntityService;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->service()->create($data);
    }
}
