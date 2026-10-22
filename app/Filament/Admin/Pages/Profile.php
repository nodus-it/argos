<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\AgentCredential;
use App\Models\User;
use App\Services\User\UserService;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * The picture goes through UserService (it removes the previous file);
     * everything else is saved as before.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record instanceof User && array_key_exists('avatar_path', $data)) {
            app(UserService::class)->setAvatar($record, $data['avatar_path']);
            unset($data['avatar_path']);
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
