<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PhaseRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'phaseRuns';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('enums.phase_runs.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('phase')
                    ->label(__('enums.phase_runs.columns.phase'))
                    ->sortable(),

                TextColumn::make('iteration')
                    ->label(__('enums.phase_runs.columns.iteration'))
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('enums.phase_runs.columns.status'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'running' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'quality_gate_failed' => 'danger',
                        'no_changes' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('started_at')
                    ->label(__('enums.phase_runs.columns.started'))
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label(__('enums.phase_runs.columns.finished'))
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('duration')
                    ->label(__('enums.phase_runs.columns.duration'))
                    ->state(fn ($record): ?int => ($record->started_at && $record->finished_at)
                        ? (int) $record->started_at->diffInSeconds($record->finished_at)
                        : null
                    )
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? $state.'s' : '—'),

                TextColumn::make('input_tokens')
                    ->label(__('enums.phase_runs.columns.input'))
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? number_format((int) $state)
                        : '—'
                    )
                    ->toggleable(),

                TextColumn::make('output_tokens')
                    ->label(__('enums.phase_runs.columns.output'))
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? number_format((int) $state)
                        : '—'
                    )
                    ->toggleable(),

                TextColumn::make('cost_usd')
                    ->label(__('enums.phase_runs.columns.cost'))
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? '$'.number_format((float) $state, 4)
                        : '—'
                    )
                    ->toggleable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
