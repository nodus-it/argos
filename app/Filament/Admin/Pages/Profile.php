<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

final class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                Select::make('locale')
                    ->label(__('common.profile.locale_label'))
                    ->options([
                        'en' => __('common.profile.locale_en'),
                        'de' => __('common.profile.locale_de'),
                    ])
                    ->native(false),
            ]);
    }
}
