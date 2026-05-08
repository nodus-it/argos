<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgentName;
use App\Enums\WorkerImageBuildStatus;
use App\Filament\Admin\Resources\WorkerImageBuildResource\Pages\ListWorkerImageBuilds;
use App\Filament\Admin\Resources\WorkerImageBuildResource\Pages\ViewWorkerImageBuild;
use App\Jobs\BuildWorkerImageJob;
use App\Models\WorkerImageBuild;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WorkerImageBuildResource extends Resource
{
    protected static ?string $model = WorkerImageBuild::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cube-transparent';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.worker');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function getModelLabel(): string
    {
        return __('worker.image_builds.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('worker.image_builds.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('tag')
                    ->label(__('worker.image_builds.fields.tag'))
                    ->copyable()
                    ->fontFamily('mono'),
                TextEntry::make('stack.name')
                    ->label(__('worker.image_builds.fields.stack')),
                TextEntry::make('agent_name')
                    ->label(__('worker.image_builds.fields.agent'))
                    ->formatStateUsing(fn (AgentName $state): string => $state->label()),
                TextEntry::make('status')
                    ->label(__('worker.image_builds.fields.status'))
                    ->badge(),
                TextEntry::make('size_bytes')
                    ->label(__('worker.image_builds.fields.size_bytes'))
                    ->state(fn (WorkerImageBuild $r): string => self::formatBytes($r->size_bytes)),
                TextEntry::make('built_at')
                    ->label(__('worker.image_builds.fields.built_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ]),
            Section::make(__('worker.image_builds.fields.build_log'))
                ->collapsed()
                ->schema([
                    TextEntry::make('build_log')
                        ->hiddenLabel()
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->fontFamily('mono')
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-size: 12px;']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tag')
                    ->label(__('worker.image_builds.fields.tag'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->limit(60),

                TextColumn::make('stack.name')
                    ->label(__('worker.image_builds.fields.stack'))
                    ->sortable(),

                TextColumn::make('agent_name')
                    ->label(__('worker.image_builds.fields.agent'))
                    ->badge()
                    ->formatStateUsing(fn (AgentName $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('worker.image_builds.fields.status'))
                    ->badge(),

                TextColumn::make('size_bytes')
                    ->label(__('worker.image_builds.fields.size_bytes'))
                    ->state(fn (WorkerImageBuild $r): string => self::formatBytes($r->size_bytes))
                    ->sortable(),

                TextColumn::make('built_at')
                    ->label(__('worker.image_builds.fields.built_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(WorkerImageBuildStatus::class),
                SelectFilter::make('worker_stack_id')
                    ->relationship('stack', 'name')
                    ->label(__('worker.image_builds.fields.stack')),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('rebuild')
                    ->label(__('worker.image_builds.actions.rebuild'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (WorkerImageBuild $record): void {
                        BuildWorkerImageJob::dispatch($record->worker_stack_id, $record->agent_name);
                        Notification::make()
                            ->success()
                            ->title(__('worker.image_builds.actions.rebuild_dispatched'))
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkerImageBuilds::route('/'),
            'view' => ViewWorkerImageBuild::route('/{record}'),
        ];
    }

    private static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }

        return number_format($val, $val < 10 ? 2 : 1).' '.$units[$i];
    }
}
