<?php

declare(strict_types=1);

namespace App\Filament\Admin\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Contracts\HasApiTokens;

/**
 * Lists and mints Sanctum API tokens for any tokenable (ApiClient, RepoProfile).
 * The plaintext is shown once on creation; only the hash is stored.
 */
class ApiTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-key';

    /** The abilities a token may carry (mirrors the REST route gates). */
    public const ABILITIES = [
        'projects:read' => 'projects:read',
        'tasks:read' => 'tasks:read',
        'tasks:write' => 'tasks:write',
    ];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('api_tokens.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('api_tokens.fields.name'))
                ->required()
                ->maxLength(255),

            CheckboxList::make('abilities')
                ->label(__('api_tokens.fields.abilities'))
                ->options(self::ABILITIES)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('api_tokens.fields.name'))
                    ->searchable(),

                TextColumn::make('abilities')
                    ->label(__('api_tokens.fields.abilities'))
                    ->badge(),

                TextColumn::make('last_used_at')
                    ->label(__('api_tokens.fields.last_used_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label(__('api_tokens.fields.created_at'))
                    ->dateTime()
                    ->since(),
            ])
            ->headerActions([
                Action::make('generate')
                    ->label(__('api_tokens.actions.create'))
                    ->icon('heroicon-o-plus')
                    ->schema([
                        TextInput::make('name')
                            ->label(__('api_tokens.fields.name'))
                            ->required()
                            ->maxLength(255),

                        CheckboxList::make('abilities')
                            ->label(__('api_tokens.fields.abilities'))
                            ->options(self::ABILITIES)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $owner = $this->getOwnerRecord();
                        if (! $owner instanceof HasApiTokens) {
                            return;
                        }

                        /** @var array<int, string> $abilities */
                        $abilities = $data['abilities'];
                        $token = $owner->createToken((string) $data['name'], $abilities);

                        // Shown once — only the hash is persisted.
                        Notification::make()
                            ->title(__('api_tokens.notifications.created_title'))
                            ->body($token->plainTextToken)
                            ->persistent()
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('api_tokens.actions.revoke')),
            ]);
    }
}
