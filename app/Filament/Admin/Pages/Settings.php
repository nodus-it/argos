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
        return 'Konfiguration';
    }

    public static function getNavigationLabel(): string
    {
        return 'Einstellungen';
    }

    public function getTitle(): string
    {
        return 'Einstellungen';
    }

    public bool $claudeTokenSet  = false;
    public string $dbConnection  = '';
    public string $workerImage   = '';

    public function mount(): void
    {
        $this->claudeTokenSet = config('argos.claude_token') !== null;
        $this->dbConnection   = config('database.default', 'sqlite');
        $this->workerImage    = config('argos.worker_image', '—');
    }
}
