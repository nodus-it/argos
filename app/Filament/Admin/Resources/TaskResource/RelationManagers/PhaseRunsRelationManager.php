<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhaseRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'phaseRuns';

    protected static ?string $title = 'Phase-Läufe';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phase')
                    ->label('Phase')
                    ->sortable(),

                TextColumn::make('iteration')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
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
                    ->label('Gestartet')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Beendet')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('duration')
                    ->label('Dauer')
                    ->state(fn ($record): ?int => ($record->started_at && $record->finished_at)
                        ? (int) $record->started_at->diffInSeconds($record->finished_at)
                        : null
                    )
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? $state.'s' : '—'),

                TextColumn::make('input_tokens')
                    ->label('Input')
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? number_format((int) $state)
                        : '—'
                    )
                    ->toggleable(),

                TextColumn::make('output_tokens')
                    ->label('Output')
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? number_format((int) $state)
                        : '—'
                    )
                    ->toggleable(),

                TextColumn::make('cost_usd')
                    ->label('Kosten')
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
