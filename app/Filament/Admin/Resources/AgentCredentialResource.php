<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\CreateAgentCredential;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\EditAgentCredential;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
use App\Models\AgentCredential;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('worker.credentials.sections.identity'))
                ->description(__('worker.credentials.sections.identity_description'))
                ->icon('heroicon-o-identification')
                ->schema([
                    Select::make('agent_name')
                        ->label(__('worker.credentials.fields.agent_name'))
                        ->options(self::agentOptions())
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

                TextColumn::make('last_validated_at')
                    ->label(__('worker.credentials.fields.last_validated_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—'),

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
