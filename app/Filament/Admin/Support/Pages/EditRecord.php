<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support\Pages;

use App\Services\EntityService;
use Filament\Resources\Pages\EditRecord as FilamentEditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Base edit page counterpart to {@see CreateRecord}: routes the update through
 * the resource's entity service.
 */
abstract class EditRecord extends FilamentEditRecord
{
    abstract protected function service(): EntityService;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->service()->update($record, $data);
    }
}
