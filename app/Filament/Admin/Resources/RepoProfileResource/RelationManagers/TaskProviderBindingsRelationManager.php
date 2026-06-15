<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\RelationManagers;

use App\Enums\AuthMethod;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\IssueTracker\ProviderSetupService;
use App\Services\OAuth\ConnectedAccountService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
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

    protected static string|\BackedEnum|null $icon = 'heroicon-o-link';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('task_providers.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->label(__('task_providers.form.provider'))
                ->options(collect(TaskProviderKind::cases())
                    ->mapWithKeys(fn (TaskProviderKind $k): array => [$k->value => $k->label()])
                    ->all())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('external_project_ref', null))
                ->native(false),

            Select::make('mode')
                ->label(__('task_providers.form.mode'))
                ->options(collect(TaskProviderMode::cases())
                    ->mapWithKeys(fn (TaskProviderMode $m): array => [$m->value => $m->label()])
                    ->all())
                ->required()
                ->native(false),

            // Unified credential picker: OAuth accounts and stored Personal
            // Access Tokens (PATs) for this provider in one field. The chosen
            // option encodes both the auth method and the source id (see
            // applyCredentialRef / mutateRecordDataUsing).
            Select::make('credential_ref')
                ->label(__('task_providers.form.credential'))
                ->options(fn (Get $get): array => $this->credentialRefOptions($get))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('external_project_ref', null))
                ->native(false)
                ->helperText(__('task_providers.form.credential_help')),

            Select::make('external_project_ref')
                ->label(__('task_providers.form.project'))
                ->options(fn (Get $get): array => $this->loadProjectRefOptions($get))
                ->searchable()
                ->native(false)
                ->placeholder(__('task_providers.form.project_placeholder'))
                ->helperText(__('task_providers.form.project_help'))
                ->nullable(),

            TagsInput::make('filters.labels')
                ->label(__('task_providers.form.labels_filter'))
                ->nullable(),

            Toggle::make('filters.close_on_complete')
                ->label(__('task_providers.form.close_on_complete'))
                ->helperText(__('task_providers.form.close_on_complete_help'))
                ->default(false),
        ]);
    }

    /**
     * Build the grouped option list for the unified credential picker: OAuth
     * accounts and stored PATs that match the chosen provider. Keys encode the
     * source: "oauth:{id}" or "pat:{ulid}".
     *
     * @return array<string, array<string, string>>
     */
    protected function credentialRefOptions(Get $get): array
    {
        $kind = TaskProviderKind::tryFrom((string) $get('kind'));

        $groups = [];

        /** @var User|null $user */
        $user = Auth::user();
        if ($user !== null) {
            $oauth = app(ConnectedAccountService::class)
                ->selectableFor($user, $kind !== null ? [$kind->providerKey()] : [])
                ->mapWithKeys(fn (ConnectedAccount $account): array => [
                    "oauth:{$account->id}" => $account->name ?? $account->nickname ?? "#{$account->id}",
                ])
                ->all();

            if ($oauth !== []) {
                $groups[__('task_providers.form.groups.oauth')] = $oauth;
            }
        }

        $pats = ProviderCredential::query()
            ->when(
                $kind !== null,
                fn ($query) => $query->where('provider', $kind->value),
            )
            ->get()
            ->mapWithKeys(fn (ProviderCredential $credential): array => [
                "pat:{$credential->id}" => $credential->label,
            ])
            ->all();

        if ($pats !== []) {
            $groups[__('task_providers.form.groups.pat')] = $pats;
        }

        return $groups;
    }

    /**
     * Translate the picker's "credential_ref" virtual value into the persisted
     * columns (auth_method + connected_account_id / provider_credential_id).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyCredentialRef(array $data): array
    {
        $ref = $data['credential_ref'] ?? null;
        unset($data['credential_ref']);

        $data['connected_account_id'] = null;
        $data['provider_credential_id'] = null;

        if (is_string($ref) && str_starts_with($ref, 'oauth:')) {
            $data['auth_method'] = AuthMethod::OAuth->value;
            $data['connected_account_id'] = (int) substr($ref, 6);
        } elseif (is_string($ref) && str_starts_with($ref, 'pat:')) {
            $data['auth_method'] = AuthMethod::Pat->value;
            $data['provider_credential_id'] = substr($ref, 4);
        }

        return $data;
    }

    /**
     * Reconstruct the picker's "credential_ref" value from a stored binding so
     * the edit form pre-selects the right option.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function hydrateCredentialRef(array $data): array
    {
        if (! empty($data['provider_credential_id'])) {
            $data['credential_ref'] = 'pat:'.$data['provider_credential_id'];
        } elseif (! empty($data['connected_account_id'])) {
            $data['credential_ref'] = 'oauth:'.$data['connected_account_id'];
        }

        return $data;
    }

    /**
     * Load the selectable project/team references for the chosen provider and
     * credential. Cached for 60s per (kind, credential_ref) to avoid hammering
     * the provider API on every live form update. API failures degrade to an
     * empty list rather than breaking the form. The currently stored value is
     * always kept selectable, even if it is not (or no longer) in the list.
     *
     * @return array<string, string>
     */
    protected function loadProjectRefOptions(Get $get): array
    {
        $kind = TaskProviderKind::tryFrom((string) $get('kind'));
        $ref = $get('credential_ref');

        $options = [];

        if ($kind !== null && is_string($ref) && $ref !== '') {
            $cacheKey = "provider_refs:{$kind->value}:{$ref}";
            $options = Cache::get($cacheKey);

            if (! is_array($options)) {
                try {
                    $options = $this->trackerForRef($kind, $ref)?->listReferences() ?? [];
                } catch (\Throwable) {
                    $options = [];
                }

                if ($options !== []) {
                    Cache::put($cacheKey, $options, now()->addSeconds(60));
                }
            }
        }

        $current = $get('external_project_ref');
        if (is_string($current) && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * Build an issue-tracker for a picker "credential_ref" value, or null when
     * the referenced source no longer exists.
     */
    protected function trackerForRef(TaskProviderKind $kind, string $ref): ?IssueTrackerContract
    {
        $registry = app(IssueTrackerRegistry::class);

        if (str_starts_with($ref, 'oauth:')) {
            $account = ConnectedAccount::find((int) substr($ref, 6));

            return $account instanceof ConnectedAccount
                ? $registry->makeFromAccount($kind, $account)
                : null;
        }

        if (str_starts_with($ref, 'pat:')) {
            $credential = ProviderCredential::find(substr($ref, 4));

            return $credential instanceof ProviderCredential
                ? $registry->makeFromProviderCredential($kind, $credential)
                : null;
        }

        return null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label(__('task_providers.columns.provider'))
                    ->formatStateUsing(fn (TaskProviderKind $state): string => $state->label()),

                TextColumn::make('mode')
                    ->label(__('task_providers.columns.mode'))
                    ->formatStateUsing(fn (TaskProviderMode $state): string => $state->label()),

                TextColumn::make('sync_status')
                    ->label(__('task_providers.columns.status'))
                    ->badge()
                    ->color(fn (TaskProviderSyncStatus $state): string => $state->color())
                    ->formatStateUsing(fn (TaskProviderSyncStatus $state): string => $state->label()),

                TextColumn::make('external_project_ref')
                    ->label(__('task_providers.columns.project'))
                    ->placeholder('—'),

                TextColumn::make('last_polled_at')
                    ->label(__('task_providers.columns.last_poll'))
                    ->since()
                    ->placeholder('—'),

                TextColumn::make('last_error')
                    ->label(__('task_providers.columns.last_error'))
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->applyCredentialRef($data)),
            ])
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => $this->hydrateCredentialRef($data))
                    ->mutateDataUsing(fn (array $data): array => $this->applyCredentialRef($data)),

                Action::make('setup')
                    ->label(__('task_providers.actions.setup'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (TaskProviderBinding $record): void {
                        if ($record->connected_account_id === null && $record->provider_credential_id === null) {
                            Notification::make()
                                ->title(__('task_providers.notifications.no_credential_title'))
                                ->body(__('task_providers.notifications.no_credential_body'))
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            app(ProviderSetupService::class)->setup($record);

                            Notification::make()
                                ->title(__('task_providers.notifications.setup_ok'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('task_providers.notifications.setup_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                DeleteAction::make(),
            ]);
    }
}
