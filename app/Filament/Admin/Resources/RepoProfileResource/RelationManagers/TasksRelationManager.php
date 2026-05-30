<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\RelationManagers;

use App\Filament\Admin\Concerns\TaskTableConcern;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TasksRelationManager extends RelationManager
{
    use TaskTableConcern;

    protected static string $relationship = 'tasks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('projects.columns.tasks');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(static::taskTableEagerLoads()))
            ->columns(static::taskTableColumns(withProject: false))
            ->filters(static::taskTableFilters(withProject: false))
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function getTabs(): array
    {
        return static::taskTableTabs();
    }
}
