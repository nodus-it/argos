<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\RelationManagers;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ConnectedAccount;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\IssueTracker\ProviderSetupService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('external_project_ref', null))
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
                ->options(function (Get $get): array {
                    /** @var User|null $user */
                    $user = Auth::user();
                    if ($user === null) {
                        return [];
                    }

                    $kind = TaskProviderKind::tryFrom((string) $get('kind'));

                    return $user->connectedAccounts()
                        ->when(
                            $kind !== null,
                            fn ($query) => $query->where('provider', $kind->providerKey()),
                        )
                        ->get()
                        ->mapWithKeys(fn (ConnectedAccount $account): array => [
                            $account->id => "{$account->provider}: ".($account->name ?? $account->nickname ?? "#{$account->id}"),
                        ])
                        ->all();
                })
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('external_project_ref', null))
                ->nullable()
                ->native(false),

            Select::make('external_project_ref')
                ->label('Projekt / Team')
                ->options(fn (Get $get): array => $this->loadProjectRefOptions($get))
                ->searchable()
                ->native(false)
                ->placeholder('Erst Provider und OAuth-Account wählen')
                ->helperText('Wird automatisch aus dem verbundenen Account geladen.')
                ->nullable(),

            TagsInput::make('filters.labels')
                ->label('Labels-Filter')
                ->nullable(),
        ]);
    }

    /**
     * Load the selectable project/team references for the chosen provider and
     * OAuth account. Cached for 60s per (kind, account) to avoid hammering the
     * provider API on every live form update. API failures degrade to an empty
     * list rather than breaking the form. The currently stored value is always
     * kept selectable, even if it is not (or no longer) in the fetched list.
     *
     * @return array<string, string>
     */
    protected function loadProjectRefOptions(Get $get): array
    {
        $kind = TaskProviderKind::tryFrom((string) $get('kind'));
        $accountId = $get('connected_account_id');

        $options = [];

        if ($kind !== null && $accountId !== null) {
            $account = ConnectedAccount::find($accountId);

            if ($account instanceof ConnectedAccount) {
                $cacheKey = "provider_refs:{$kind->value}:{$account->id}";
                $options = Cache::get($cacheKey);

                if (! is_array($options)) {
                    try {
                        $options = app(IssueTrackerRegistry::class)
                            ->makeFromAccount($kind, $account)
                            ->listReferences();
                    } catch (\Throwable) {
                        $options = [];
                    }

                    if ($options !== []) {
                        Cache::put($cacheKey, $options, now()->addSeconds(60));
                    }
                }
            }
        }

        $current = $get('external_project_ref');
        if (is_string($current) && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
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
