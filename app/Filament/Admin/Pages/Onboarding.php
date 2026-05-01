<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\RepoProfile;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\Rule;

class Onboarding extends Page
{
    protected string $view = 'filament.admin.pages.onboarding';

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

    public bool $claudeTokenSet = false;

    public string $workerImage = '';

    public string $name = '';

    public string $url = '';

    public string $token = '';

    public string $platform = '';

    public string $default_branch = 'main';

    public function mount(): void
    {
        $this->claudeTokenSet = (bool) config('argos.claude_token');
        $this->workerImage = config('argos.worker_image', '');
    }

    public function createProject(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'token' => ['nullable', 'string', 'max:500'],
            'platform' => ['required', Rule::in(['github', 'gitlab'])],
            'default_branch' => ['required', 'string', 'max:255'],
        ]);

        RepoProfile::create([
            'name' => $this->name,
            'url' => $this->url,
            'token' => $this->token ?: null,
            'platform' => $this->platform,
            'default_branch' => $this->default_branch,
        ]);

        Notification::make()->title('Projekt angelegt — Argos ist bereit!')->success()->send();
        $this->redirect(route('filament.admin.resources.tasks.index'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createProject')
                ->label('Projekt anlegen')
                ->icon('heroicon-o-rocket-launch')
                ->action(fn () => $this->createProject()),

            Action::make('skip')
                ->label('Überspringen')
                ->color('gray')
                ->url(route('filament.admin.resources.tasks.index')),
        ];
    }
}
