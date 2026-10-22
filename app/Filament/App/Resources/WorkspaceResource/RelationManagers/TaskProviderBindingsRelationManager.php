<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\RelationManagers;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\Credentials\CredentialRefResolver;
use App\Services\IssueTracker\ProviderSetupService;
use App\Services\IssueTracker\TaskProviderBindingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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

    /** Integrations are workspace settings: admins of the workspace (and Argos admins). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('update', $ownerRecord) ?? false;
    }

    private static function isCustom(Get $get): bool
    {
        return $get('kind') === TaskProviderKind::Custom->value;
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
                ->visible(fn (Get $get): bool => ! self::isCustom($get))
                ->label(__('task_providers.form.mode'))
                ->options(collect(TaskProviderMode::cases())
                    ->mapWithKeys(fn (TaskProviderMode $m): array => [$m->value => $m->label()])
                    ->all())
                ->required()
                ->native(false),

            // Unified credential picker: OAuth accounts and stored Personal
            // Access Tokens (PATs) for this provider in one field. The chosen
            // option encodes both the auth method and the source id (see
            // TaskProviderBindingService / mutateRecordDataUsing).
            Select::make('credential_ref')
                ->visible(fn (Get $get): bool => ! self::isCustom($get))
                ->label(__('task_providers.form.credential'))
                ->options(fn (Get $get): array => $this->credentialRefOptions($get))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('external_project_ref', null))
                ->native(false)
                ->helperText(__('task_providers.form.credential_help')),

            Select::make('external_project_ref')
                ->visible(fn (Get $get): bool => ! self::isCustom($get))
                ->label(__('task_providers.form.project'))
                ->options(fn (Get $get): array => $this->loadProjectRefOptions($get))
                ->searchable()
                ->native(false)
                ->placeholder(__('task_providers.form.project_placeholder'))
                ->helperText(__('task_providers.form.project_help'))
                ->nullable(),

            TagsInput::make('filters.labels')
                ->visible(fn (Get $get): bool => ! self::isCustom($get))
                ->label(__('task_providers.form.labels_filter'))
                ->nullable(),

            // Own systems: they create tasks over the REST API and hear back here.
            TextInput::make('external_project_ref')
                ->visible(fn (Get $get): bool => self::isCustom($get))
                ->label(__('task_providers.custom.name'))
                ->helperText(__('task_providers.custom.name_help'))
                ->required()
                ->maxLength(120),
            TextInput::make('callback_url')
                ->visible(fn (Get $get): bool => self::isCustom($get))
                ->label(__('task_providers.custom.callback_url'))
                ->helperText(__('task_providers.custom.callback_url_help'))
                ->url()
                ->maxLength(2000)
                ->nullable(),
            TextInput::make('webhook_secret')
                ->visible(fn (Get $get, ?TaskProviderBinding $record): bool => self::isCustom($get) && $record !== null)
                ->label(__('task_providers.custom.secret'))
                ->helperText(__('task_providers.custom.secret_help'))
                ->readOnly()
                ->dehydrated(false),

            Toggle::make('close_on_merge')
                ->label(__('task_providers.form.close_on_merge'))
                ->helperText(__('task_providers.form.close_on_merge_help'))
                ->default(true),
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
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return [];
        }
        $kind = TaskProviderKind::tryFrom((string) $get('kind'));
        $options = app(CredentialRefResolver::class)->options($user, $kind !== null ? [$kind->providerKey()] : [], $kind !== null ? [$kind->value] : null);

        return array_filter([
            __('task_providers.form.groups.oauth') => $options['oauth'],
            __('task_providers.form.groups.pat') => $options['pat'],
        ]);
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
        /** @var User|null $user */
        $user = Auth::user();
        $refs = app(CredentialRefResolver::class);
        $registry = app(IssueTrackerRegistry::class);
        $account = $user !== null ? $refs->account($user, $ref) : null;
        if ($account !== null) {
            return $registry->makeFromAccount($kind, $account);
        }
        $credential = $refs->credential($ref);

        return $credential !== null ? $registry->makeFromProviderCredential($kind, $credential) : null;
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

                IconColumn::make('close_on_merge')
                    ->label(__('task_providers.columns.close_on_merge'))
                    ->boolean()
                    ->visibleFrom('md'),

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
                    ->using(fn (array $data): TaskProviderBinding => app(TaskProviderBindingService::class)->createFor($this->getOwnerRecord(), $this->user(), $data)),
            ])
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => [...$data, 'credential_ref' => CredentialRefResolver::fromColumns($data['connected_account_id'] ?? null, $data['provider_credential_id'] ?? null)])
                    ->using(fn (TaskProviderBinding $record, array $data): TaskProviderBinding => app(TaskProviderBindingService::class)->updateBy($record, $this->user(), $data)),

                Action::make('setup')
                    ->visible(fn (TaskProviderBinding $record): bool => ! $record->kind->isCustom())
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

                DeleteAction::make()
                    ->using(function (TaskProviderBinding $record): bool {
                        app(TaskProviderBindingService::class)->deleteBy($record, $this->user());

                        return true;
                    }),
            ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
