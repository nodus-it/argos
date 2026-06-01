<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\IntegrationProvider;
use App\Enums\ProviderCredentialStatus;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\CreateProviderCredential;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\EditProviderCredential;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\ListProviderCredentials;
use App\Models\ProviderCredential;
use App\Services\Integrations\ProviderSetupGuide;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class ProviderCredentialResource extends Resource
{
    protected static ?string $model = ProviderCredential::class;

    // Pin the slug so route names, deep links, and the onboarding whitelist
    // stay predictable (matches the 'provider-credentials.*' whitelist entry).
    protected static ?string $slug = 'provider-credentials';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-key';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.configuration');
    }

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function getModelLabel(): string
    {
        return __('credentials.pat.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('credentials.pat.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('credentials.pat.sections.identity'))
                ->schema([
                    // Provider first — it (and the instance URL) drive the
                    // pre-filled "create token" link below.
                    Select::make('provider')
                        ->label(__('credentials.pat.fields.provider'))
                        ->options(self::providerOptions())
                        ->required()
                        ->live()
                        ->native(false),

                    TextInput::make('instance_url')
                        ->label(__('credentials.pat.fields.instance_url'))
                        ->helperText(__('credentials.pat.fields.instance_url_help'))
                        ->url()
                        ->live(onBlur: true)
                        ->maxLength(255),

                    Placeholder::make('pat_guide')
                        ->hiddenLabel()
                        ->content(fn (Get $get) => self::patGuide($get)),

                    TextInput::make('label')
                        ->label(__('credentials.pat.fields.label'))
                        ->helperText(__('credentials.pat.fields.label_help'))
                        ->required()
                        ->maxLength(255),

                    Select::make('status')
                        ->label(__('credentials.pat.fields.status'))
                        ->options(ProviderCredentialStatus::class)
                        ->default(ProviderCredentialStatus::Active->value)
                        ->required()
                        ->native(false),
                ]),

            Section::make(__('credentials.pat.sections.auth'))
                ->schema([
                    TextInput::make('token')
                        ->label(__('credentials.pat.fields.token'))
                        ->helperText(__('credentials.pat.fields.token_help'))
                        ->password()
                        ->revealable()
                        ->required(),

                    TextInput::make('scopes_hint')
                        ->label(__('credentials.pat.fields.scopes_hint'))
                        ->helperText(__('credentials.pat.fields.scopes_hint_help'))
                        ->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('credentials.pat.fields.label'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider')
                    ->label(__('credentials.pat.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (IntegrationProvider $state): string => $state->label())
                    ->color(fn (IntegrationProvider $state): string => $state->color()),

                TextColumn::make('instance_url')
                    ->label(__('credentials.pat.fields.instance_url'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('credentials.pat.fields.status'))
                    ->badge(),

                TextColumn::make('last_validated_at')
                    ->label(__('credentials.pat.fields.last_validated_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options(self::providerOptions()),
                SelectFilter::make('status')
                    ->options(ProviderCredentialStatus::class),
            ])
            ->recordActions([
                self::testConnectionAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * "Test connection": make a real, cheap API call (listReferences) with the
     * stored token. On success the credential is marked Active + timestamped;
     * a failure surfaces the provider's error without flipping the status.
     */
    public static function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label(__('credentials.pat.actions.test'))
            ->icon('heroicon-o-signal')
            ->action(function (ProviderCredential $record): void {
                try {
                    app(IssueTrackerRegistry::class)
                        ->makeRaw($record->provider->value, $record->token, $record->getInstanceUrl())
                        ->listReferences();

                    $record->forceFill([
                        'status' => ProviderCredentialStatus::Active,
                        'last_validated_at' => now(),
                    ])->save();

                    Notification::make()
                        ->title(__('credentials.pat.notifications.test_ok'))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('credentials.pat.notifications.test_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Inline guidance for creating a token at the chosen provider: a pre-filled
     * deep link plus the required scopes. Reacts to provider + instance URL.
     */
    public static function patGuide(Get $get): string|View
    {
        $provider = IntegrationProvider::tryFrom((string) $get('provider'));
        if ($provider === null) {
            return __('credentials.pat.guide.choose_provider');
        }

        $guide = app(ProviderSetupGuide::class)->pat($provider, $get('instance_url'));

        return view('filament.partials.provider-setup-guide', [
            'url' => $guide['url'],
            'buttonLabel' => __('credentials.pat.guide.button', ['provider' => $provider->label()]),
            'scopes' => $guide['scopes'],
            'scopesLabel' => __('credentials.pat.guide.scopes'),
            'note' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        $opts = [];
        foreach (IntegrationProvider::cases() as $provider) {
            $opts[$provider->value] = $provider->label();
        }

        return $opts;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderCredentials::route('/'),
            'create' => CreateProviderCredential::route('/create'),
            'edit' => EditProviderCredential::route('/{record}/edit'),
        ];
    }
}
