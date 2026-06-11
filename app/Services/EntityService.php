<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Base for entity services. Provides the central create/update/delete path so
 * persistence lives in a service rather than in the presentation layer, with
 * generic implementations that concrete services override when an entity needs
 * its own rules. A concrete service names its model and adds the entity's
 * domain operations (validation, activation, …).
 */
abstract class EntityService
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $model = $this->model();
            $record = new $model;
            $record->fill($data);
            $record->save();

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->update($data);

            return $record;
        });
    }

    public function delete(Model $record): void
    {
        DB::transaction(function () use ($record): void {
            $record->delete();
        });
    }

    protected function emit(object $event): void
    {
        event($event);
    }
}
