<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\Shared\WorkspaceForm;
use App\Filament\Support\Pages\CreateRecord;
use App\Models\WorkerStack;
use App\Services\Workspace\Environment\EnvAudit;
use App\Services\Workspace\WorkspaceSetupService;
use App\Support\DocsLinkAction;
use App\Support\GateRows;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * New workspace, guided: repository first, then what Argos read from it,
 * the live demo, optional extras and the start of the setup. "Set up
 * everything yourself" switches to CreateWorkspaceExpert.
 */
class CreateWorkspace extends CreateRecord
{
    use CreatesWorkspace;
    use HasWizard;

    protected static string $resource = WorkspaceResource::class;

    protected static bool $canCreateAnother = false;

    /** @var array{project?: string, stack?: string|null, services?: list<string>, service_sources?: array<string, string>, gates?: list<string>|null, setup?: string, preview?: string, open?: list<string>, missing?: list<string>} */
    public array $detected = [];

    public function mount(): void
    {
        parent::mount();
        $draft = session()->pull(CreateWorkspaceExpert::DRAFT_KEY);
        if (is_array($draft)) {
            $this->form->fill($draft);
        }
    }

    public function getTitle(): string
    {
        return __('workspaces.create.title');
    }

    /** @return list<Step> */
    public function getSteps(): array
    {
        return [
            Step::make(__('workspaces.create.steps.repo'))
                ->schema([
                    Text::make(__('workspaces.create.steps.repo_help'))->color('gray'),
                    WorkspaceForm::platformSection(),
                    ...WorkspaceForm::authenticationSections(),
                    WorkspaceForm::repositorySection(),
                    WorkspaceForm::generalSection(),
                ])
                ->afterValidation(fn (Set $set) => $this->detect($set)),
            Step::make(__('workspaces.create.steps.detected'))
                ->schema([
                    Text::make(__('workspaces.create.steps.detected_help'))->color('gray'),
                    View::make('filament.app.workspace.detected')->viewData(fn (): array => ['rows' => $this->detectedRows()]),
                    WorkspaceForm::workerSection(gated: false)
                        ->heading(__('workspaces.create.change_detected'))
                        ->aside(false)
                        ->collapsible()
                        ->collapsed(),
                ]),
            Step::make(__('workspaces.create.steps.demo'))
                ->schema([
                    Text::make(__('workspaces.create.steps.demo_help'))->color('gray'),
                    WorkspaceForm::liveDemoSection(gated: false),
                    TextEntry::make('demo_env_state')
                        ->label(__('workspaces.setup.env_heading'))
                        ->state(fn (): array => $this->envLines())
                        ->listWithLineBreaks(),
                    WorkspaceForm::envSecretsSection(gated: false)->aside(false)->collapsible()->collapsed(),
                ]),
            Step::make(__('workspaces.create.steps.optional'))
                ->schema([
                    Text::make(__('workspaces.create.steps.optional_help'))->color('gray'),
                    TextEntry::make('team_state')
                        ->label(__('workspaces.create.team_and_tracker'))
                        ->state(__('workspaces.create.team_and_tracker_help')),
                    WorkspaceForm::resourcesSection()->aside(false)->collapsible()->collapsed(),
                    WorkspaceForm::automationSection()->aside(false)->collapsible()->collapsed(),
                ]),
            Step::make(__('workspaces.create.steps.go'))
                ->schema([
                    Text::make(__('workspaces.create.steps.go_help'))->color('gray'),
                    View::make('filament.app.workspace.detected')->viewData(fn (): array => ['rows' => $this->detectedRows()]),
                    Text::make(__('workspaces.create.go_next')),
                ]),
        ];
    }

    /** Read the repository before anything is saved and preset stack and services. */
    public function detect(Set $set): void
    {
        $setup = app(WorkspaceSetupService::class);
        $draft = $setup->draft(WorkspaceForm::dehydrate($this->data ?? []));
        $audit = app(EnvAudit::class);

        try {
            $found = $setup->inspect($draft);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['data.repo_url' => __('workspaces.create.unreadable', ['error' => $e->getMessage()])]);
        }

        $serviceSources = $audit->serviceSources($draft);
        $services = array_keys($serviceSources);
        $sources = $setup->sources($draft);
        $env = $audit->audit($draft);
        $this->detected = [
            'project' => $found['project'],
            'stack' => $found['stack'],
            'services' => $services,
            'service_sources' => $serviceSources,
            'gates' => $setup->availableGates($draft),
            'setup' => $sources['setup'],
            'preview' => $sources['preview'],
            'open' => $env['open'],
            'missing' => $env['missing'],
        ];

        if ($found['stack'] !== null && blank($this->data['worker_stack_id'] ?? null)) {
            $set('worker_stack_id', WorkerStack::query()->where('name', $found['stack'])->value('id'));
        }
        $set('worker_services', array_values(array_unique([...($this->data['worker_services'] ?? []), ...$services])));
    }

    /** @return list<array{label: string, value: string, reason: string, found: bool}> */
    public function detectedRows(): array
    {
        $d = $this->detected;
        if ($d === []) {
            return [];
        }
        $row = fn (string $key, string $value, string $reason = '', bool $found = true): array => [
            'label' => (string) __('workspaces.create.rows.'.$key), 'value' => $value, 'reason' => $reason, 'found' => $found,
        ];
        $stack = $d['stack'] !== null ? (WorkerStack::query()->where('name', $d['stack'])->value('label') ?? $d['stack']) : null;
        $service = fn (string $key): array => isset($d['service_sources'][$key])
            ? $row($key, (string) __('workspaces.create.on'), (string) __('workspaces.create.from', ['file' => $d['service_sources'][$key]]))
            : $row($key, (string) __('workspaces.create.not_found'), (string) __('workspaces.create.service_hint'), false);
        $gateLabels = array_map(fn (string $g): string => GateRows::ROWS[$g]['label'] ?? $g, $d['gates'] ?? []);

        return [
            $row('project', (string) __('workspaces.create.projects.'.$d['project'])),
            $stack !== null ? $row('stack', $stack, (string) __('workspaces.create.stack_reason')) : $row('stack', (string) __('workspaces.create.not_found'), (string) __('workspaces.create.no_stack'), false),
            $service('mysql'),
            $service('redis'),
            match (true) {
                $d['gates'] === null => $row('gates', (string) __('workspaces.create.unknown'), (string) __('workspaces.create.gates_unknown'), false),
                $d['gates'] === [] => $row('gates', (string) __('workspaces.create.not_found'), (string) __('workspaces.create.no_gates'), false),
                default => $row('gates', implode(', ', $gateLabels), (string) __('workspaces.create.from', ['file' => 'composer.json'])),
            },
            $row('setup', (string) __('workspaces.setup.source.'.$d['setup'])),
            $row('preview', (string) __('workspaces.setup.source.'.$d['preview'])),
        ];
    }

    /** @return list<string> */
    public function envLines(): array
    {
        $missing = $this->detected['missing'] ?? [];
        $open = $this->detected['open'] ?? [];

        return array_values(array_filter([
            $missing !== [] ? __('workspaces.setup.env_missing').': '.implode(', ', $missing) : null,
            $open !== [] ? __('workspaces.setup.env_open').': '.implode(', ', $open) : null,
        ])) ?: [(string) __('workspaces.setup.env_none')];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('workspaces.create.submit'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('expert')
                ->label(__('workspaces.create.expert'))
                ->link()
                ->action(function (): void {
                    session()->put(CreateWorkspaceExpert::DRAFT_KEY, $this->data);
                    $this->redirect(WorkspaceResource::getUrl('create-expert'));
                }),
            DocsLinkAction::make('workspace-settings'),
        ];
    }
}
