<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
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
