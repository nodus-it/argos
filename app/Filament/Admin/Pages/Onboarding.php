<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\RepoProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Onboarding extends Page implements HasForms
{
    use InteractsWithForms;

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

    /** @var array<string, mixed> */
    public array $projectData = [];

    public function mount(): void
    {
        $this->claudeTokenSet = (bool) config('argos.claude_token');
        $this->workerImage = config('argos.worker_image', '');
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('projectData')
            ->schema([
                TextInput::make('name')
                    ->label('Projektname')
                    ->required()
                    ->maxLength(255),

                TextInput::make('url')
                    ->label('Repo-URL')
                    ->required()
                    ->url()
                    ->maxLength(500),

                TextInput::make('token')
                    ->label('Personal Access Token')
                    ->password()
                    ->revealable()
                    ->maxLength(500),

                Select::make('platform')
                    ->options([
                        'github' => 'GitHub',
                        'gitlab' => 'GitLab',
                    ])
                    ->required(),

                TextInput::make('default_branch')
                    ->label('Default Branch')
                    ->required()
                    ->default('main')
                    ->maxLength(255),
            ]);
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function createProject(): void
    {
        $data = $this->form->getState();
        RepoProfile::create($data);
        Notification::make()->title('Projekt angelegt — Argos ist bereit!')->success()->send();
        $this->redirect(route('filament.admin.resources.tasks.index'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('skip')
                ->label('Überspringen')
                ->color('gray')
                ->url(route('filament.admin.resources.tasks.index')),
        ];
    }
}
