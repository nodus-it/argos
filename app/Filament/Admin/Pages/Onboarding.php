<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Credentials\CredentialStore;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Anthropic\AnthropicTokenValidator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Onboarding extends Page
{
    protected string $view = 'filament.admin.pages.onboarding';

    public string $tokenSource = 'none';

    public string $claudeToken = '';

    public string $workerImage = '';

    public bool $githubOAuthAvailable = false;

    public bool $githubConnected = false;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-rocket-launch';
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return 'Einrichtung';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! RepoProfile::exists();
    }

    public function getTitle(): string
    {
        return 'Argos einrichten';
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    private function refreshState(): void
    {
        $this->tokenSource = app(CredentialStore::class)->claudeTokenSource();
        $this->workerImage = (string) config('argos.worker_image', '');
        $this->githubOAuthAvailable = (bool) config('services.github.client_id')
            && (bool) config('services.github.client_secret');

        /** @var User|null $user */
        $user = Auth::user();
        $this->githubConnected = $user !== null && $user->connectedAccount('github') !== null;
    }

    public function saveClaudeToken(): void
    {
        if ($this->tokenSource === 'env') {
            Notification::make()
                ->title('Token kommt aus der Umgebungsvariable')
                ->warning()
                ->send();

            return;
        }

        $token = trim($this->claudeToken);

        if ($token === '') {
            Notification::make()->title('Bitte einen Token eingeben')->warning()->send();

            return;
        }

        $valid = app(AnthropicTokenValidator::class)->validate($token);

        if ($valid === false) {
            Notification::make()
                ->title('Token ungültig')
                ->body('Der eingegebene Token wurde von der API abgelehnt.')
                ->danger()
                ->send();

            return;
        }

        app(CredentialStore::class)->setClaudeToken($token);
        $this->claudeToken = '';
        $this->refreshState();

        if ($valid === null) {
            Notification::make()
                ->title('Token gespeichert')
                ->body('Hinweis: Token konnte nicht gegen die API geprüft werden — Verbindung nicht erreichbar.')
                ->warning()
                ->send();
        } else {
            Notification::make()->title('Token gespeichert')->success()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createProject')
                ->label('Erstes Projekt anlegen')
                ->icon('heroicon-o-rocket-launch')
                ->url(route('filament.admin.resources.repo-profiles.create')),
        ];
    }
}
