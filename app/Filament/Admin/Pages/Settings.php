<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\Anthropic\CredentialStore;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * @property Schema $form
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.admin.pages.settings';

    public ?array $data = [];

    public string $tokenSource = 'none';

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

    public function mount(): void
    {
        $store = app(CredentialStore::class);
        $this->tokenSource = $store->claudeTokenSource();

        $this->form->fill(['claude_token' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('settings.token_section_heading'))
                    ->description($this->tokenSourceDescription())
                    ->components([
                        TextInput::make('claude_token')
                            ->label(__('settings.token_field.label'))
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
                ->title(__('settings.notifications.env_token_title'))
                ->body(__('settings.notifications.env_token_body'))
                ->warning()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $token = trim((string) ($data['claude_token'] ?? ''));

        if ($token === '') {
            Notification::make()->title(__('settings.notifications.empty_token'))->warning()->send();

            return;
        }

        $valid = app(AnthropicTokenValidator::class)->validate($token);

        if ($valid === false) {
            Notification::make()
                ->title(__('settings.notifications.invalid_token_title'))
                ->body(__('settings.notifications.invalid_token_body'))
                ->danger()
                ->send();

            return;
        }

        app(CredentialStore::class)->setClaudeToken($token);
        $this->tokenSource = 'file';
        $this->form->fill(['claude_token' => '']);

        if ($valid === null) {
            Notification::make()
                ->title(__('settings.notifications.saved_title'))
                ->body(__('settings.notifications.saved_unreachable_body'))
                ->warning()
                ->send();
        } else {
            Notification::make()->title(__('settings.notifications.saved_title'))->success()->send();
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

        Notification::make()->title(__('settings.notifications.removed'))->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('settings.actions.save'))
                ->submit('save')
                ->disabled(fn (): bool => $this->tokenSource === 'env'),

            Action::make('clearToken')
                ->label(__('settings.actions.remove'))
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
            'env' => __('settings.token_source.env'),
            'file' => __('settings.token_source.file'),
            default => __('settings.token_source.none'),
        };
    }

    private function tokenInputPlaceholder(): string
    {
        return $this->tokenSource === 'env'
            ? __('settings.token_field.placeholder_env')
            : __('settings.token_field.placeholder_other');
    }

    private function tokenInputHelpText(): string
    {
        return match ($this->tokenSource) {
            'env' => __('settings.token_field.help_env'),
            'file' => __('settings.token_field.help_file'),
            default => __('settings.token_field.help_none'),
        };
    }
}
