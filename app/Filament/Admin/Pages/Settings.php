<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Pages\Page;

class Settings extends Page
{
    protected string $view = 'filament.admin.pages.settings';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.navigation_label');
    }

    public static function getNavigationSort(): ?int
    {
        return 999;
    }

    public function getTitle(): string
    {
        return __('settings.title');
    }
}
