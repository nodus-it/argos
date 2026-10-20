<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\ClaudeModel;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\CreateAgentCredential;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\EditAgentCredential;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
use App\Models\AgentCredential;
use App\Models\User;
use App\Services\Workspace\AgentPool;
use App\Workers\Agents\AgentRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AgentCredentialResource extends Resource
{
    protected static ?string $model = AgentCredential::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-key';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.worker');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getModelLabel(): string
    {
        return __('worker.credentials.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('worker.credentials.plural');
    }

    /**
     * Non-admins only ever see their own agents (their own subscription).
     *
     * @return Builder<AgentCredential>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        return self::isAdmin() ? $query : $query->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('worker.credentials.sections.identity'))
                ->description(__('worker.credentials.sections.identity_description'))
                ->icon('heroicon-o-identification')
                ->schema([
                    Select::make('agent_name')
                        ->label(__('worker.credentials.fields.agent_name'))
                        ->options(fn (): array => self::isAdmin()
                            ? self::agentOptions()
                            : array_intersect_key(self::agentOptions(), [AgentName::ClaudeCode->value => true]))
                        ->required()
                        ->live()
                        ->native(false)
                        ->afterStateUpdated(fn (Set $set) => $set('credentials', null)),

                    TextInput::make('name')
                        ->label(__('worker.credentials.fields.name'))
                        ->helperText(__('worker.credentials.fields.name_help'))
                        ->required()
                        ->maxLength(255),

                    Select::make('status')
                        ->label(__('worker.credentials.fields.status'))
                        ->options(AgentCredentialStatus::class)
                        ->default(AgentCredentialStatus::Active->value)
                        ->required()
                        ->native(false),

                    Select::make('user_id')
                        ->label(__('worker.credentials.fields.owner'))
                        ->helperText(__('worker.credentials.fields.owner_help'))
                        ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder(__('worker.credentials.fields.owner_shared'))
                        ->searchable()
                        ->visible(fn (): bool => self::isAdmin()),

                    TextInput::make('max_concurrent_sessions')
                        ->label(__('worker.credentials.fields.max_concurrent_sessions'))
                        ->helperText(__('worker.credentials.fields.max_concurrent_sessions_help'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->default(1)
                        ->required()
                        ->visible(fn (): bool => self::isAdmin()),

                    Select::make('default_model')
                        ->label(__('worker.credentials.fields.default_model'))
                        ->helperText(__('worker.credentials.fields.default_model_help'))
                        ->options(self::modelOptions())
                        ->native(false)
                        ->visible(fn (Get $get): bool => self::isClaudeAgent($get)),
                ]),

            Section::make(__('worker.credentials.sections.auth'))
                ->description(__('worker.credentials.sections.auth_description'))
                ->icon('heroicon-o-key')
                ->schema([
                    // Claude: simple token field. Stored as ['token' => '…'].
                    TextInput::make('credentials.token')
                        ->label(__('worker.credentials.fields.token'))
                        ->helperText(__('worker.credentials.fields.token_help'))
                        ->password()
                        ->revealable()
                        ->required(fn (Get $get): bool => self::isClaudeAgent($get))
                        ->visible(fn (Get $get): bool => self::isClaudeAgent($get)),

                    // Codex: paste full auth.json contents. Stored verbatim
                    // (decoded → encrypted JSON array). The form keeps the
                    // raw text in a virtual field; we encode/decode in the
                    // mutate hooks (see Pages\Edit/CreateAgentCredential).
                    Textarea::make('credentials_json')
                        ->label(__('worker.credentials.fields.auth_json'))
                        ->helperText(__('worker.credentials.fields.auth_json_help'))
                        ->rows(12)
                        ->extraInputAttributes([
                            'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;',
                            'spellcheck' => 'false',
                        ])
                        ->required(fn (Get $get): bool => self::isCodexAgent($get))
                        ->visible(fn (Get $get): bool => self::isCodexAgent($get)),
                    // NOT dehydrated(false) — we need the raw text in
                    // $data so the page's mutate-hook can decode it
                    // into the `credentials` (encrypted-array) column.
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('worker.credentials.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('agent_name')
                    ->label(__('worker.credentials.fields.agent_name'))
                    ->badge()
                    ->formatStateUsing(fn (AgentName $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('worker.credentials.fields.status'))
                    ->badge(),

                TextColumn::make('user.name')
                    ->label(__('worker.credentials.fields.owner'))
                    ->placeholder(__('worker.credentials.fields.owner_shared'))
                    ->visible(fn (): bool => self::isAdmin()),

                TextColumn::make('max_concurrent_sessions')
                    ->label(__('worker.credentials.fields.sessions'))
                    ->formatStateUsing(fn (AgentCredential $record): string => app(AgentPool::class)->activeSessions($record).' / '.$record->max_concurrent_sessions)
                    ->visibleFrom('md'),

                TextColumn::make('usage_limited_until')
                    ->label(__('worker.credentials.fields.usage_limited_until'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->visibleFrom('lg'),

                TextColumn::make('last_validated_at')
                    ->label(__('worker.credentials.fields.last_validated_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—')
                    ->visibleFrom('md'),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('agent_name')
                    ->options(self::agentOptions()),
                SelectFilter::make('status')
                    ->options(AgentCredentialStatus::class),
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
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * @return array<string, string>
     */
    public static function agentOptions(): array
    {
        $registry = app(AgentRegistry::class);
        $opts = [];
        foreach ($registry->specs() as $spec) {
            $opts[$spec->name->value] = $spec->label;
        }

        return $opts;
    }

    /**
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        $options = [];
        foreach (ClaudeModel::cases() as $model) {
            $options[$model->value] = $model->label();
        }

        return $options;
    }

    public static function isAdmin(): bool
    {
        return Gate::allows('administer-argos');
    }

    public static function isClaudeAgent(Get $get): bool
    {
        return $get('agent_name') === AgentName::ClaudeCode->value;
    }

    public static function isCodexAgent(Get $get): bool
    {
        return $get('agent_name') === AgentName::Codex->value;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentCredentials::route('/'),
            'create' => CreateAgentCredential::route('/create'),
            'edit' => EditAgentCredential::route('/{record}/edit'),
        ];
    }
}
