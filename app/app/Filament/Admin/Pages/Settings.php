<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Credentials\CredentialStore;
use Filament\Notifications\Notification;
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

    public string $claudeToken = '';

    public string $dbHost = '';

    public string $dbPort = '3306';

    public string $dbDatabase = '';

    public string $dbUsername = '';

    public string $dbPassword = '';

    public function mount(): void
    {
        $store = app(CredentialStore::class);

        $token = $store->getClaudeToken();
        if ($token !== null) {
            $this->claudeToken = $token;
        }

        $dbConfig = $store->getDbConfig();
        if ($dbConfig !== null) {
            $this->dbHost     = $dbConfig['ARGOS_DB_HOST']     ?? '';
            $this->dbPort     = $dbConfig['ARGOS_DB_PORT']     ?? '3306';
            $this->dbDatabase = $dbConfig['ARGOS_DB_DATABASE'] ?? '';
            $this->dbUsername = $dbConfig['ARGOS_DB_USERNAME'] ?? '';
            $this->dbPassword = $dbConfig['ARGOS_DB_PASSWORD'] ?? '';
        }
    }

    public function save(): void
    {
        $store = app(CredentialStore::class);

        if ($this->claudeToken !== '') {
            $store->saveClaudeToken($this->claudeToken);
        }

        $dbConfig = [
            'ARGOS_DB_HOST'     => $this->dbHost,
            'ARGOS_DB_PORT'     => $this->dbPort,
            'ARGOS_DB_DATABASE' => $this->dbDatabase,
            'ARGOS_DB_USERNAME' => $this->dbUsername,
            'ARGOS_DB_PASSWORD' => $this->dbPassword,
        ];

        $hasDbConfig = array_filter($dbConfig, fn (string $v): bool => $v !== '') !== [];
        if ($hasDbConfig) {
            $store->saveDbConfig($dbConfig);
        }

        Notification::make()
            ->title('Einstellungen gespeichert')
            ->success()
            ->send();
    }
}
