<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\Account\Pages;

use App\Models\AgentCredential;
use App\Models\User;
use App\Services\User\UserService;
use App\Filament\App\Clusters\Account;
use Filament\Auth\Pages\EditProfile;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Panel;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * My account → Profile (`/account/profile`): the panel's profile page. Not a
 * cluster member itself — Filament's profile URL ignores cluster prefixes —
 * so it joins the account navigation by hand.
 */
final class Profile extends EditProfile
{
    protected static ?string $slug = 'account/profile';

    /** Keeps `auth.profile`, which Filament's getProfileUrl() asks for. */
    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'profile';
    }

    public function getSubNavigation(): array
    {
        return $this->generateNavigationItems(Account::getClusteredComponents());
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return Account::getSubNavigationPosition();
    }

    public static function getNavigationLabel(): string
    {
        return __('account.profile');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    // Render with the full admin index layout so Argos CSS (scoped to .fi-main)
    // applies consistently with resource edit forms.
    public static function isSimple(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->inlineLabel(false)
            ->components([
                Section::make(__('common.profile.section_account'))
                    ->description(__('common.profile.section_account_description'))
                    ->icon('heroicon-o-user-circle')
                    ->aside()
                    ->components([
                        FileUpload::make('avatar_path')
                            ->label(__('common.profile.avatar_label'))
                            ->helperText(__('common.profile.avatar_help'))
                            ->avatar()
                            ->image()
                            ->imageEditor()
                            ->disk(User::AVATAR_DISK)
                            ->directory('avatars')
                            ->visibility('private')
                            ->maxSize(4096),
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        Select::make('locale')
                            ->label(__('common.profile.locale_label'))
                            ->options([
                                'en' => __('common.profile.locale_en'),
                                'de' => __('common.profile.locale_de'),
                            ])
                            ->native(false),
                    ]),
                Section::make(__('workspaces.notifications.settings'))
                    ->description(__('workspaces.notifications.settings_description'))
                    ->icon('heroicon-o-bell')
                    ->aside()
                    ->components([
                        ...array_map(
                            fn (string $kind): Toggle => Toggle::make('notification_prefs.'.$kind)
                                ->label(__('workspaces.notifications.kind_'.$kind))
                                ->default(true)
                                ->formatStateUsing(fn ($state): bool => $state !== false),
                            User::NOTIFICATION_KINDS,
                        ),
                        View::make('filament.app.account.browser-notifications'),
                    ]),
                Section::make(__('common.profile.section_auto'))
                    ->description(__('common.profile.section_auto_description'))
                    ->icon('heroicon-o-bolt')
                    ->aside()
                    ->components([
                        Toggle::make('auto_task_consent')
                            ->label(__('common.profile.auto_task_consent'))
                            ->helperText(__('common.profile.auto_task_consent_help')),
                    ]),
                Section::make(__('common.profile.section_agents'))
                    ->description(__('common.profile.section_agents_description'))
                    ->icon('heroicon-o-cpu-chip')
                    ->aside()
                    ->components([
                        View::make('filament.app.account.own-agents')
                            ->viewData(fn (): array => [
                                'agents' => AgentCredential::query()->where('user_id', auth()->id())->orderBy('name')->get(),
                            ]),
                    ]),
                Section::make(__('common.profile.section_password'))
                    ->description(__('common.profile.section_password_description'))
                    ->icon('heroicon-o-key')
                    ->aside()
                    ->components([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ]),
            ]);
    }

    /**
     * Saved through UserService like every other user write.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        return app(UserService::class)->updateProfile($record, $data);
    }
}
