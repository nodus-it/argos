<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Phase\PhaseRunner;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\RelationManagers\PhaseRunsRelationManager;
use App\Models\RepoProfile;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-queue-list';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Aufgaben';
    }

    public static function getNavigationLabel(): string
    {
        return 'Tasks';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            Select::make('repo_profile_id')
                ->label('Repo-Profil')
                ->options(RepoProfile::all()->pluck('name', 'id'))
                ->required(),

            Textarea::make('description')
                ->rows(8)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('repoProfile.name')
                    ->label('Repo')
                    ->sortable(),

                TextColumn::make('current_phase')
                    ->label('Phase')
                    ->placeholder('—'),

                TextColumn::make('current_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'running'             => 'warning',
                        'completed'           => 'success',
                        'failed'              => 'danger',
                        'quality_gate_failed' => 'danger',
                        'no_changes'          => 'info',
                        default               => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Erstellt')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make(),
                self::phaseAction('concept', 'Concept', 'heroicon-o-light-bulb'),
                self::phaseAction('implement', 'Implement', 'heroicon-o-code-bracket'),
                self::phaseAction('push', 'Push', 'heroicon-o-arrow-up-tray'),
                self::makeLogsAction(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [
            PhaseRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view'   => ViewTask::route('/{record}'),
        ];
    }

    public static function makeLogsAction(): Action
    {
        return Action::make('logs')
            ->label('Logs')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->fillForm(fn (Task $record): array => [
                'log' => self::readBgLog($record->name, $record->current_phase),
            ])
            ->form([
                Textarea::make('log')
                    ->label('Ausgabe')
                    ->rows(20)
                    ->readOnly()
                    ->extraAttributes(['style' => 'font-family: monospace; font-size: 0.75rem; white-space: pre;']),
            ])
            ->modalHeading(fn (Task $record): string => "Logs: {$record->name} / " . ($record->current_phase ?? '—'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen');
    }

    private static function readBgLog(string $taskName, ?string $phase): string
    {
        if ($phase === null) {
            return '(noch keine Phase gestartet)';
        }

        $configDir = config('argos.config_dir');
        $logPath = "{$configDir}/tasks/{$taskName}/{$phase}.bg.log";

        if (!file_exists($logPath)) {
            return "(kein Log unter {$logPath})";
        }

        $content = file_get_contents($logPath) ?: '';

        $lines = explode("\n", $content);
        if (count($lines) > 200) {
            $lines = array_slice($lines, -200);
            array_unshift($lines, '... (abgeschnitten — letzte 200 Zeilen)');
        }

        return implode("\n", $lines);
    }

    private static function phaseAction(string $phase, string $label, string $icon): Action
    {
        return Action::make($phase)
            ->label($label)
            ->icon($icon)
            ->action(function (Task $record) use ($phase, $label): void {
                if ($record->phaseRuns()->where('status', 'running')->exists()) {
                    Notification::make()->title('Phase läuft bereits')->warning()->send();
                    return;
                }
                app(PhaseRunner::class)->startBackground($record, $phase);
                Notification::make()->title("{$label} gestartet")->success()->send();
            });
    }
}
