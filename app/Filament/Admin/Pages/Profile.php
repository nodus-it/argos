<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
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
