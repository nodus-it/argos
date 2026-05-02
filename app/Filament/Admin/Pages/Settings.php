<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Credentials\CredentialStore;
use App\Services\Anthropic\AnthropicTokenValidator;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.admin.pages.settings';

    public ?array $data = [];

    public string $tokenSource = 'none';

    public string $dbConnection = '';

    public string $workerImage = '';

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

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public function getTitle(): string
    {
        return 'Einstellungen';
    }

    public function mount(): void
    {
        $store = app(CredentialStore::class);
        $this->tokenSource = $store->claudeTokenSource();
        $this->dbConnection = config('database.default', 'sqlite');
        $this->workerImage = config('argos.worker_image', '—');

        $this->form->fill(['claude_token' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Claude OAuth Token')
                    ->description($this->tokenSourceDescription())
                    ->components([
                        TextInput::make('claude_token')
                            ->label('Token')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->placeholder($this->tokenInputPlaceholder())
                            ->maxLength(500)
                            ->disabled(fn (): bool => $this->tokenSource === 'env')
                            ->helperText($this->tokenInputHelpText()),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if ($this->tokenSource === 'env') {
            Notification::make()
                ->title('Token kommt aus der Umgebungsvariable')
                ->body('Der Token kommt aus der Umgebung und kann hier nicht geändert werden.')
                ->warning()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $token = trim((string) ($data['claude_token'] ?? ''));

        if ($token === '') {
            Notification::make()->title('Bitte einen Token eingeben')->warning()->send();

            return;
        }

        $valid = app(AnthropicTokenValidator::class)->validate($token);

        if ($valid === false) {
            Notification::make()
                ->title('Token ungültig')
                ->body('Der eingegebene Token wurde von der API abgelehnt. Bitte prüfen und erneut versuchen.')
                ->danger()
                ->send();

            return;
        }

        app(CredentialStore::class)->setClaudeToken($token);
        $this->tokenSource = 'file';
        $this->form->fill(['claude_token' => '']);

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

    public function clearToken(): void
    {
        if ($this->tokenSource !== 'file') {
            return;
        }

        app(CredentialStore::class)->deleteClaudeToken();
        $this->tokenSource = app(CredentialStore::class)->claudeTokenSource();
        $this->form->fill(['claude_token' => '']);

        Notification::make()->title('Token entfernt')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Token speichern')
                ->submit('save')
                ->disabled(fn (): bool => $this->tokenSource === 'env'),

            Action::make('clearToken')
                ->label('Token entfernen')
                ->color('danger')
                ->requiresConfirmation()
                ->action('clearToken')
                ->visible(fn (): bool => $this->tokenSource === 'file'),
        ];
    }

    public function getClaudeTokenSet(): bool
    {
        return $this->tokenSource !== 'none';
    }

    private function tokenSourceDescription(): string
    {
        return match ($this->tokenSource) {
            'env' => 'Token ist über die Umgebungsvariable CLAUDE_CODE_OAUTH_TOKEN konfiguriert. Eingaben hier sind deaktiviert.',
            'file' => 'Token ist gespeichert. Eingabe überschreibt den vorhandenen Wert.',
            default => 'Kein Token konfiguriert — Phasen können nicht ausgeführt werden, bis du einen Token hinterlegst.',
        };
    }

    private function tokenInputPlaceholder(): string
    {
        return $this->tokenSource === 'env' ? '••• (aus Environment) •••' : 'sk-ant-oat01-…';
    }

    private function tokenInputHelpText(): string
    {
        return match ($this->tokenSource) {
            'env' => 'Token kommt aus der Umgebung — im UI nicht änderbar.',
            'file' => 'Wird im Config-Verzeichnis abgelegt (mode 0600).',
            default => 'Wird im Config-Verzeichnis abgelegt (mode 0600). Token via "claude setup-token" erzeugen.',
        };
    }
}
