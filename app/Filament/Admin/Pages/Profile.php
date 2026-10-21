<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\AgentCredential;
use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

final class Profile extends EditProfile
{
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
                        View::make('filament.admin.pages.partials.browser-notifications'),
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
                        View::make('filament.admin.pages.partials.own-agents')
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
}
