<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\ConnectedAccount;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ConnectedAccounts extends Page
{
    protected string $view = 'filament.admin.pages.connected-accounts';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-link';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Konfiguration';
    }

    public static function getNavigationLabel(): string
    {
        return 'Verknüpfte Accounts';
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public function getTitle(): string
    {
        return 'Verknüpfte Accounts';
    }

    /**
     * @return array<string, ConnectedAccount|null>
     */
    public function getConnectedAccounts(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return [
            'github' => $user->connectedAccount('github'),
        ];
    }

    protected function getHeaderActions(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $hasGitHub = $user->connectedAccount('github') !== null;

        $actions = [];

        if (! $hasGitHub) {
            $actions[] = Action::make('connectGitHub')
                ->label('Mit GitHub verbinden')
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.github.redirect'));
        }

        return $actions;
    }

    public function disconnectGitHub(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'github')->delete();

        Notification::make()
            ->title('GitHub-Verbindung getrennt')
            ->success()
            ->send();
    }
}
