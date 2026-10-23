<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources;

use App\Filament\App\Clusters\System;
use App\Filament\App\Clusters\System\Resources\UserResource\Pages\CreateUser;
use App\Filament\App\Clusters\System\Resources\UserResource\Pages\EditUser;
use App\Filament\App\Clusters\System\Resources\UserResource\Pages\ListUsers;
use App\Filament\Concerns\AdminOnly;
use App\Models\User;
use App\Services\User\UserService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Minimal user administration (admins only): create people for pair
 * programming in workspaces and grant the admin role.
 */
class UserResource extends Resource
{
    use AdminOnly;

    protected static ?string $cluster = System::class;

    protected static ?string $slug = 'users';

    public static function getNavigationLabel(): string
    {
        return __('system.users');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    protected static ?string $model = User::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-users';
    }

    public static function getModelLabel(): string
    {
        return __('users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('users.sections.account'))
                ->icon('heroicon-o-user')
                ->schema([
                    TextInput::make('name')
                        ->label(__('users.fields.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label(__('users.fields.email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    TextInput::make('password')
                        ->label(__('users.fields.password'))
                        ->helperText(fn (?User $record): string => $record === null ? __('users.fields.password_help_create') : __('users.fields.password_help_edit'))
                        ->password()
                        ->revealable()
                        ->minLength(8),

                    Select::make('locale')
                        ->label(__('users.fields.locale'))
                        ->options(['de' => 'Deutsch', 'en' => 'English'])
                        ->native(false),

                    Toggle::make('is_admin')
                        ->label(__('users.fields.is_admin'))
                        ->helperText(__('users.fields.is_admin_help'))
                        ->disabled(fn (?User $record): bool => $record !== null && $record->id === auth()->id()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('users.fields.email'))
                    ->searchable(),
                IconColumn::make('is_admin')
                    ->label(__('users.fields.is_admin'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('users.fields.created_at'))
                    ->dateTime()
                    ->since()
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                self::accessLinkAction(),
                EditAction::make(),
                self::deleteAction(),
            ])
            ->defaultSort('name');
    }

    /**
     * A fresh access link inside a ready-to-send welcome message (opening
     * the dialog creates the link; it voids an older one).
     */
    public static function accessLinkAction(): Action
    {
        return Action::make('accessLink')
            ->label(__('users.access_link.action'))
            ->icon('heroicon-o-key')
            ->modalHeading(fn (User $record): string => __('users.access_link.heading', ['name' => $record->name]))
            ->modalDescription(fn (User $record): string => __('users.access_link.description', ['name' => $record->name, 'hours' => max(1, (int) config('auth.passwords.users.expire') / 60)]))
            ->fillForm(fn (User $record): array => ['text' => app(UserService::class)->welcomeText($record, app(UserService::class)->accessLink($record))])
            ->schema([
                Textarea::make('text')
                    ->label(__('users.access_link.text'))
                    ->rows(12)
                    ->readOnly()
                    ->extraInputAttributes(['onclick' => 'this.select()']),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament-actions::view.single.modal.actions.close.label'));
    }

    /** Deletes through UserService so the last admin can never be removed. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (User $record): bool => $record->id !== auth()->id())
            ->using(function (User $record): bool {
                try {
                    app(UserService::class)->remove($record);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return false;
                }

                return true;
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
