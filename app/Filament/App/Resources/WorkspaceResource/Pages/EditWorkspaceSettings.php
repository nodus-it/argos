<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\App\Resources\WorkspaceResource\RelationManagers\ApiTokensRelationManager;
use App\Filament\App\Resources\WorkspaceResource\RelationManagers\MembersRelationManager;
use App\Filament\App\Resources\WorkspaceResource\RelationManagers\TaskProviderBindingsRelationManager;
use App\Filament\Shared\WorkspaceForm;
use App\Filament\Support\Pages\EditRecord;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntityService;
use App\Services\Workspace\WorkspaceService;
use App\Support\DocsLinkAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Settings of a workspace (its admins and Argos admins): General,
 * Environment, Resources, Integrations, Automation, Members.
 *
 * Each settings tab is its own form with its own state path and save button,
 * so saving one tab never writes half-edited fields of another. Integrations
 * and Members are relation managers embedded in their tabs. Writes go through
 * WorkspaceService::updateSettings and WorkspaceMemberService.
 */
class EditWorkspaceSettings extends EditRecord
{
    protected static string $resource = WorkspaceResource::class;

    /** Fields per settings form (state path => columns). */
    private const FIELDS = [
        'data' => ['name', 'branch_pattern', 'default_branch'],
        // The token itself is never filled into the browser.
        'access' => ['auth_method', 'connected_account_id'],
        'environment' => ['worker_source', 'worker_stack_id', 'worker_services', 'worker_service_config', 'live_demo_enabled', 'composer_registries', 'worker_env'],
        'resources' => ['max_sessions', 'max_previews', 'cpu_limit', 'memory_limit_mb', 'cleanup_after_days'],
        'automation' => ['automation_user_id', 'auto_settings'],
    ];

    /** @var array<string, mixed>|null */
    public ?array $access = [];

    /** @var array<string, mixed>|null */
    public ?array $environment = [];

    /** @var array<string, mixed>|null */
    public ?array $resources = [];

    /** @var array<string, mixed>|null */
    public ?array $automation = [];

    protected function service(): EntityService
    {
        return app(WorkspaceService::class);
    }

    public function getTitle(): string|Htmlable
    {
        return __('workspaces.settings.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->workspace()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('workspaces.settings.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('workspaces.settings.back'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => WorkspaceResource::getUrl('view', ['record' => $this->workspace()])),
            DocsLinkAction::make('workspaces'),
        ];
    }

    /** General: name, repository (fixed), default branch, branch pattern. */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            WorkspaceForm::generalSection(gated: false),
            WorkspaceForm::repositorySummarySection(),
        ]);
    }

    public function accessForm(Schema $schema): Schema
    {
        return $this->settingsSchema($schema, 'access')->components([WorkspaceForm::accessSection()]);
    }

    public function environmentForm(Schema $schema): Schema
    {
        return $this->settingsSchema($schema, 'environment')->components([
            WorkspaceForm::workerSection(gated: false),
            WorkspaceForm::liveDemoSection(gated: false),
            WorkspaceForm::envSecretsSection(gated: false),
            WorkspaceForm::setupSection(),
        ]);
    }

    public function resourcesForm(Schema $schema): Schema
    {
        return $this->settingsSchema($schema, 'resources')->components([
            WorkspaceForm::resourcesSection(),
        ]);
    }

    public function automationForm(Schema $schema): Schema
    {
        return $this->settingsSchema($schema, 'automation')->components([
            WorkspaceForm::automationSection(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('settings')
                ->key('settings')
                ->persistTabInQueryString('tab')
                ->contained(false)
                ->tabs([
                    Tab::make(__('workspaces.settings.tabs.general'))
                        ->key('general')
                        ->icon('heroicon-o-folder')
                        ->schema([$this->formComponent('form', 'save'), $this->formComponent('accessForm', 'saveAccess')]),
                    Tab::make(__('workspaces.settings.tabs.environment'))
                        ->key('environment')
                        ->icon('heroicon-o-cpu-chip')
                        ->schema([$this->formComponent('environmentForm', 'saveEnvironment')]),
                    Tab::make(__('workspaces.settings.tabs.resources'))
                        ->key('resources')
                        ->icon('heroicon-o-adjustments-vertical')
                        ->schema([$this->formComponent('resourcesForm', 'saveResources')]),
                    Tab::make(__('workspaces.settings.tabs.integrations'))
                        ->key('integrations')
                        ->icon('heroicon-o-link')
                        ->schema([
                            $this->relationManager(TaskProviderBindingsRelationManager::class, 'trackers'),
                            $this->relationManager(ApiTokensRelationManager::class, 'tokens'),
                        ]),
                    Tab::make(__('workspaces.settings.tabs.automation'))
                        ->key('automation')
                        ->icon('heroicon-o-bolt')
                        ->schema([$this->formComponent('automationForm', 'saveAutomation')]),
                    Tab::make(__('workspaces.settings.tabs.members'))
                        ->key('members')
                        ->icon('heroicon-o-users')
                        ->schema([$this->relationManager(MembersRelationManager::class, 'members')]),
                ]),
        ]);
    }

    /** Renew the repository access: a new token, or a connected account instead. */
    public function saveAccess(): void
    {
        $this->authorizeAccess();

        /** @var Schema $schema */
        $schema = $this->getSchema('accessForm');
        $data = $schema->getState();
        $attributes = ($data['auth_method'] ?? null) === 'oauth'
            ? ['auth_method' => 'oauth', 'connected_account_id' => $data['connected_account_id'] ?? null, 'token' => null]
            : ['auth_method' => 'pat', 'connected_account_id' => null, ...(filled($data['token'] ?? null) ? ['token' => (string) $data['token']] : [])];
        /** @var User $user */
        $user = auth()->user();
        app(WorkspaceService::class)->updateSettings($this->workspace(), $user, $attributes);
        $schema->fill([...Arr::only($attributes, self::FIELDS['access']), 'token' => null]);

        Notification::make()->success()->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))->send();
    }

    public function saveEnvironment(): void
    {
        $this->saveSettingsForm('environmentForm');
    }

    public function saveResources(): void
    {
        $this->saveSettingsForm('resourcesForm');
    }

    public function saveAutomation(): void
    {
        $this->saveSettingsForm('automationForm');
    }

    /** Fill every settings form with only its own columns (nothing else reaches the browser). */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $attributes = $this->workspace()->attributesToArray();
        $this->form->fill(Arr::only($attributes, self::FIELDS['data']));
        foreach (['access', 'environment', 'resources', 'automation'] as $form) {
            $this->getSchema($form.'Form')?->fill(Arr::only($attributes, self::FIELDS[$form]));
        }

        $this->callHook('afterFill');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return WorkspaceForm::dehydrate($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Workspace $record */
        /** @var User $user */
        $user = auth()->user();

        return app(WorkspaceService::class)->updateSettings($record, $user, $data);
    }

    /** Stay on the settings page after saving the general tab. */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    private function saveSettingsForm(string $form): void
    {
        $this->authorizeAccess();

        /** @var Schema $schema */
        $schema = $this->getSchema($form);
        $data = WorkspaceForm::dehydrate($schema->getState());
        $this->handleRecordUpdate($this->workspace(), Arr::only($data, self::FIELDS[$schema->getStatePath()]));

        Notification::make()->success()->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))->send();
    }

    private function settingsSchema(Schema $schema, string $statePath): Schema
    {
        return $schema
            ->columns(1)
            ->model($this->getRecord())
            ->operation('edit')
            ->statePath($statePath);
    }

    private function formComponent(string $schema, string $submit): Form
    {
        return Form::make([EmbeddedSchema::make($schema)])
            ->id($schema)
            ->livewireSubmitHandler($submit)
            ->footer([
                Actions::make([
                    Action::make($submit)
                        ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                        ->submit($submit),
                ]),
            ]);
    }

    /**
     * A relation manager in a titled section (the table's own heading is
     * hidden in this theme).
     *
     * @param  class-string<RelationManager>  $manager
     */
    private function relationManager(string $manager, string $section): Section
    {
        $heading = $section === 'members' ? __('workspaces.settings.members.title') : __("workspaces.settings.integrations.{$section}");

        return Section::make($heading)
            ->description(__("workspaces.settings.integrations.{$section}_description"))
            ->visible(fn (): bool => $manager::canViewForRecord($this->getRecord(), static::class))
            ->schema([
                Livewire::make($manager, fn (): array => ['ownerRecord' => $this->getRecord(), 'pageClass' => static::class])
                    ->key(class_basename($manager)),
            ]);
    }

    private function workspace(): Workspace
    {
        /** @var Workspace $record */
        $record = $this->getRecord();

        return $record;
    }
}
