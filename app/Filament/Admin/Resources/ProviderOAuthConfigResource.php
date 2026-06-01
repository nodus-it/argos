<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\IntegrationProvider;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\CreateProviderOAuthConfig;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\EditProviderOAuthConfig;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\ListProviderOAuthConfigs;
use App\Models\ProviderOAuthConfig;
use App\Services\Integrations\ProviderSetupGuide;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Unique;

class ProviderOAuthConfigResource extends Resource
{
    protected static ?string $model = ProviderOAuthConfig::class;

    // Without this, Filament derives "provider-o-auth-configs" from the class
    // name (it splits "OAuth" into "o-auth"). Pin a clean slug so the route
    // names, deep links, and onboarding whitelist all line up.
    protected static ?string $slug = 'provider-oauth-configs';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.configuration');
    }

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function getModelLabel(): string
    {
        return __('credentials.oauth.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('credentials.oauth.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('credentials.oauth.sections.app'))
                ->schema([
                    Select::make('provider')
                        ->label(__('credentials.oauth.fields.provider'))
                        ->options(self::providerOptions())
                        ->required()
                        ->live()
                        ->native(false)
                        // Recompute the callback URL the user must register as
                        // soon as a provider is chosen.
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('callback_url', self::callbackUrl((string) $state)))
                        // One OAuth app per (provider, instance) — mirrors the
                        // DB unique index so duplicates surface as a form error
                        // rather than a 500.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('instance_url', (string) $get('instance_url')),
                        ),

                    TextInput::make('instance_url')
                        ->label(__('credentials.oauth.fields.instance_url'))
                        ->helperText(__('credentials.oauth.fields.instance_url_help'))
                        ->url()
                        ->live(onBlur: true)
                        ->maxLength(255),

                    TextInput::make('callback_url')
                        ->label(__('credentials.oauth.fields.callback_url'))
                        ->helperText(__('credentials.oauth.fields.callback_url_help'))
                        // Read-only (not disabled) so the value stays selectable
                        // for copy/paste into the provider's OAuth app. Never
                        // persisted — derived from APP_URL + the chosen provider.
                        ->readOnly()
                        ->dehydrated(false)
                        ->placeholder(__('credentials.oauth.fields.callback_url_placeholder'))
                        ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(self::callbackUrl((string) $get('provider')))),

                    Placeholder::make('oauth_guide')
                        ->hiddenLabel()
                        ->content(fn (Get $get) => self::oauthGuide($get)),

                    Toggle::make('enabled')
                        ->label(__('credentials.oauth.fields.enabled'))
                        ->default(true),
                ]),

            Section::make(__('credentials.oauth.sections.credentials'))
                ->schema([
                    TextInput::make('client_id')
                        ->label(__('credentials.oauth.fields.client_id'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('client_secret')
                        ->label(__('credentials.oauth.fields.client_secret'))
                        ->helperText(__('credentials.oauth.fields.client_secret_help'))
                        ->password()
                        ->revealable()
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('credentials.oauth.fields.provider'))
                    ->badge()
                    ->formatStateUsing(fn (IntegrationProvider $state): string => $state->label())
                    ->color(fn (IntegrationProvider $state): string => $state->color()),

                TextColumn::make('instance_url')
                    ->label(__('credentials.oauth.fields.instance_url'))
                    ->placeholder(__('credentials.oauth.public_instance'))
                    ->formatStateUsing(fn (?string $state): string => ($state === null || $state === '')
                        ? __('credentials.oauth.public_instance')
                        : $state),

                TextColumn::make('client_id')
                    ->label(__('credentials.oauth.fields.client_id'))
                    ->limit(20),

                IconColumn::make('enabled')
                    ->label(__('credentials.oauth.fields.enabled'))
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options(self::providerOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('provider');
    }

    /**
     * The fixed OAuth callback URL for the chosen provider, derived from
     * APP_URL — the value the admin must paste into the provider's OAuth app.
     * Returns '' before a provider is chosen so the placeholder shows.
     */
    public static function callbackUrl(string $provider): string
    {
        if ($provider === '') {
            return '';
        }

        return rtrim((string) config('app.url'), '/')."/auth/{$provider}/callback";
    }

    /**
     * Inline guidance for creating the OAuth app at the chosen provider: a
     * pre-filled deep link (where supported) plus the required scopes. Reacts
     * to provider + instance URL.
     */
    public static function oauthGuide(Get $get): string|View
    {
        $provider = IntegrationProvider::tryFrom((string) $get('provider'));
        if ($provider === null) {
            return __('credentials.oauth.guide.choose_provider');
        }

        $instanceUrl = (string) $get('instance_url');
        $guide = app(ProviderSetupGuide::class)->oauthApp(
            $provider,
            $instanceUrl,
            rtrim((string) config('app.url'), '/'),
            self::callbackUrl($provider->value),
        );

        return view('filament.partials.provider-setup-guide', [
            'url' => $guide['url'],
            'buttonLabel' => __('credentials.oauth.guide.button', ['provider' => $provider->label()]),
            'scopes' => $guide['scopes'],
            'scopesLabel' => __('credentials.oauth.guide.scopes'),
            'note' => $guide['url'] === null ? __('credentials.oauth.guide.manual_note') : __('credentials.oauth.guide.callback_note'),
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
            'index' => ListProviderOAuthConfigs::route('/'),
            'create' => CreateProviderOAuthConfig::route('/create'),
            'edit' => EditProviderOAuthConfig::route('/{record}/edit'),
        ];
    }
}
