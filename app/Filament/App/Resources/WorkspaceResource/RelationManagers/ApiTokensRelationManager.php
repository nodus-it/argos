<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\RelationManagers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Api\ApiTokenService;
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
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lists and mints Sanctum API tokens for any tokenable (ApiClient, Workspace).
 * The plaintext is shown once on creation; only the hash is stored.
 */
class ApiTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-key';

    /**
     * Abilities offered for this owner (see ApiTokenService::abilitiesFor).
     *
     * @return array<string, string>
     */
    private function abilities(): array
    {
        $abilities = app(ApiTokenService::class)->abilitiesFor($this->getOwnerRecord());

        return array_combine($abilities, $abilities);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('api_tokens.title');
    }

    /** A workspace's tokens belong to its settings (admins of the workspace). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if ($ownerRecord instanceof Workspace) {
            return Auth::user()?->can('update', $ownerRecord) ?? false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
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
                ->options(fn (): array => $this->abilities())
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
                    ->schema(fn (Schema $schema): Schema => $this->form($schema))
                    ->action(function (array $data): void {
                        $owner = $this->getOwnerRecord();
                        if (! $owner instanceof HasApiTokens) {
                            return;
                        }
                        /** @var User $user */
                        $user = Auth::user();
                        /** @var list<string> $abilities */
                        $abilities = array_values($data['abilities']);
                        $token = app(ApiTokenService::class)->mint($owner, $user, (string) $data['name'], $abilities);

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
                    ->label(__('api_tokens.actions.revoke'))
                    ->using(function (PersonalAccessToken $record): bool {
                        /** @var User $user */
                        $user = Auth::user();
                        app(ApiTokenService::class)->revoke($record, $user);

                        return true;
                    }),
            ]);
    }
}
