<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\RelationManagers;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ConnectedAccount;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\IssueTracker\ProviderSetupService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TaskProviderBindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'taskProviderBindings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Task-Provider';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->label('Provider')
                ->options(collect(TaskProviderKind::cases())
                    ->mapWithKeys(fn (TaskProviderKind $k): array => [$k->value => $k->label()])
                    ->all())
                ->required()
                ->native(false),

            Select::make('mode')
                ->label('Modus')
                ->options(collect(TaskProviderMode::cases())
                    ->mapWithKeys(fn (TaskProviderMode $m): array => [$m->value => $m->label()])
                    ->all())
                ->required()
                ->native(false),

            Select::make('connected_account_id')
                ->label('OAuth-Account')
                ->options(function (): array {
                    /** @var User|null $user */
                    $user = Auth::user();
                    if ($user === null) {
                        return [];
                    }

                    return $user->connectedAccounts()
                        ->get()
                        ->mapWithKeys(fn (ConnectedAccount $account): array => [
                            $account->id => "{$account->provider}: ".($account->name ?? $account->nickname ?? "#{$account->id}"),
                        ])
                        ->all();
                })
                ->nullable()
                ->native(false),

            TextInput::make('external_project_ref')
                ->label('Projekt-Referenz (owner/repo)')
                ->placeholder('owner/repo')
                ->nullable(),

            TagsInput::make('filters.labels')
                ->label('Labels-Filter')
                ->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label('Provider')
                    ->formatStateUsing(fn (TaskProviderKind $state): string => $state->label()),

                TextColumn::make('mode')
                    ->label('Modus')
                    ->formatStateUsing(fn (TaskProviderMode $state): string => $state->label()),

                TextColumn::make('sync_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (TaskProviderSyncStatus $state): string => $state->color())
                    ->formatStateUsing(fn (TaskProviderSyncStatus $state): string => $state->label()),

                TextColumn::make('external_project_ref')
                    ->label('Projekt')
                    ->placeholder('—'),

                TextColumn::make('last_polled_at')
                    ->label('Letzter Poll')
                    ->since()
                    ->placeholder('—'),

                TextColumn::make('last_error')
                    ->label('Letzter Fehler')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),

                Action::make('setup')
                    ->label('Einrichten')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (TaskProviderBinding $record): void {
                        $account = $record->connectedAccount;
                        if (! $account instanceof ConnectedAccount) {
                            Notification::make()
                                ->title('Kein OAuth-Account verknüpft')
                                ->body('Bitte zuerst einen OAuth-Account im Binding auswählen.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            app(ProviderSetupService::class)->setup($record, $account);

                            Notification::make()
                                ->title('Provider eingerichtet')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $record->last_error = $e->getMessage();
                            $record->save();

                            Notification::make()
                                ->title('Einrichtung fehlgeschlagen')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                DeleteAction::make(),
            ]);
    }
}
