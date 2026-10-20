<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Resources\WorkspaceResource\Pages;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Services\Workspace\WorkspaceMemberService;
use App\Services\Workspace\WorkspaceService;
use App\Services\Workspace\WorkspaceShipService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

/**
 * The workspace: chat with Claude on the left, editor and preview on the
 * right. The chat itself is the `workspace.thread-chat` Livewire component.
 */
class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    /** Shown in the ship dialog (changed files, unpushed commits). */
    public string $shipSummary = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Opening a stopped workspace brings it back (files were kept).
        $workspace = $this->workspace();
        if ($workspace->pr_number !== null && $workspace->pr_state === 'open' && ($workspace->pr_synced_at === null || $workspace->pr_synced_at->lt(now()->subMinutes(5)))) {
            app(WorkspaceShipService::class)->syncPullRequest($workspace);
        }
        if ($workspace->status === WorkspaceStatus::Stopped && auth()->user()?->can('operate', $workspace)) {
            try {
                app(WorkspaceService::class)->start($workspace);
            } catch (WorkspaceException $e) {
                Notification::make()->title($e->getMessage())->warning()->send();
            }
        }
    }

    public function workspace(): Workspace
    {
        /** @var Workspace $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getView(): string
    {
        return 'filament.admin.resources.workspace.view-workspace';
    }

    public function getTitle(): string|Htmlable
    {
        return $this->workspace()->name;
    }

    /** `/ship` in the composer opens the ship dialog. */
    #[On('workspace-ship')]
    public function openShipDialog(): void
    {
        $this->mountAction('ship');
    }

    /** Re-read the record while the container changes state (polled). */
    public function refreshStatus(): void
    {
        $this->record = $this->workspace()->fresh() ?? $this->record;
    }

    protected function getHeaderActions(): array
    {
        $workspace = $this->workspace();

        return [
            Action::make('start')
                ->label(__('workspaces.actions.start'))
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => in_array($this->workspace()->status, [WorkspaceStatus::Stopped, WorkspaceStatus::Failed], true)
                    && (auth()->user()?->can('operate', $this->workspace()) ?? false))
                ->action(function (): void {
                    try {
                        app(WorkspaceService::class)->start($this->workspace());
                        Notification::make()->title(__('workspaces.actions.started'))->success()->send();
                    } catch (WorkspaceException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->refreshStatus();
                }),

            Action::make('ship')
                ->label(__('workspaces.ship.action'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                    && (auth()->user()?->can('ship', $this->workspace()) ?? false))
                ->modalHeading(__('workspaces.ship.heading'))
                ->modalWidth('2xl')
                ->fillForm(function (): array {
                    /** @var User $user */
                    $user = auth()->user();
                    try {
                        $draft = app(WorkspaceShipService::class)->draft($this->workspace(), $user);
                    } catch (\Throwable) {
                        $draft = ['subject' => '', 'body' => '', 'changed_files' => 0, 'ahead' => 0];
                    }
                    $gates = app(WorkspaceGateService::class)->summary($this->workspace());
                    $failed = app(WorkspaceGateService::class)->latest($this->workspace())?->failedGates() !== [];
                    $this->shipSummary = __('workspaces.ship.summary', ['files' => $draft['changed_files'], 'ahead' => $draft['ahead']])
                        .($draft['subject'] === '' ? ' '.__('workspaces.ship.no_draft') : '')
                        .($gates !== null ? ' '.__($failed ? 'workspaces.gates.ship_warning' : 'workspaces.gates.ship_summary', ['summary' => $gates]) : '');

                    return ['subject' => $draft['subject'], 'body' => $draft['body'], 'draft' => false];
                })
                ->modalDescription(fn (): string => $this->shipSummary)
                ->schema([
                    TextInput::make('subject')
                        ->label(__('workspaces.ship.subject'))
                        ->required()
                        ->maxLength(120),
                    Textarea::make('body')
                        ->label(__('workspaces.ship.body'))
                        ->rows(5),
                    Toggle::make('draft')
                        ->label(__('workspaces.ship.draft'))
                        ->visible(fn (): bool => $this->workspace()->pr_number === null),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();
                    try {
                        $url = app(WorkspaceShipService::class)->ship($this->workspace(), $user, (string) $data['subject'], (string) ($data['body'] ?? ''), (bool) ($data['draft'] ?? false));
                        Notification::make()->title(__('workspaces.ship.shipped', ['url' => $url]))->success()->send();
                    } catch (WorkspaceException $e) {
                        Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->refreshStatus();
                }),

            ActionGroup::make([
                Action::make('gates')
                    ->label(__('workspaces.gates.run'))
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                        && (auth()->user()?->can('operate', $this->workspace()) ?? false))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = auth()->user();
                        try {
                            app(WorkspaceGateService::class)->run($this->workspace(), $user);
                            Notification::make()->title(__('workspaces.gates.started'))->success()->send();
                            $this->dispatch('workspace-tab', tab: 'review');
                        } catch (WorkspaceException $e) {
                            Notification::make()->title($e->getMessage())->warning()->send();
                        }
                    }),
                Action::make('rebase')
                    ->label(__('workspaces.ship.rebase'))
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->visible(fn (): bool => $this->workspace()->status === WorkspaceStatus::Running
                        && (auth()->user()?->can('ship', $this->workspace()) ?? false))
                    ->action(function (): void {
                        /** @var User $user */
                        $user = auth()->user();
                        $thread = $this->workspace()->threads()->whereNull('archived_at')->latest('updated_at')->first();
                        if ($thread === null) {
                            Notification::make()->title(__('workspaces.ship.no_thread'))->warning()->send();

                            return;
                        }
                        try {
                            app(ThreadService::class)->send($thread, $user, __('workspaces.ship.rebase_prompt', ['branch' => $this->workspace()->branch]));
                        } catch (WorkspaceException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('members')
                    ->label(__('workspaces.actions.add_member'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => auth()->user()?->can('manageMembers', $this->workspace()) ?? false)
                    ->modalDescription(__('workspaces.actions.subscription_hint'))
                    ->schema([
                        Select::make('user_id')
                            ->label(__('workspaces.members.user'))
                            ->options(fn (): array => User::query()
                                ->whereNotIn('id', $this->workspace()->members()->pluck('user_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        Select::make('role')
                            ->label(__('workspaces.members.role'))
                            ->options(collect(WorkspaceRole::cases())->mapWithKeys(fn (WorkspaceRole $r): array => [$r->value => $r->label()])->all())
                            ->default(WorkspaceRole::Editor->value)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var User $actor */
                        $actor = auth()->user();
                        $member = User::query()->findOrFail($data['user_id']);
                        try {
                            app(WorkspaceMemberService::class)->add($this->workspace(), $actor, $member, WorkspaceRole::from($data['role']));
                            Notification::make()->title(__('workspaces.actions.member_added', ['name' => $member->name]))->success()->send();
                        } catch (WorkspaceException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('stop')
                    ->label(__('workspaces.actions.stop'))
                    ->icon('heroicon-o-stop')
                    ->visible(fn (): bool => in_array($this->workspace()->status, [WorkspaceStatus::Running, WorkspaceStatus::Failed], true)
                        && (auth()->user()?->can('operate', $this->workspace()) ?? false))
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(WorkspaceService::class)->stop($this->workspace());
                        Notification::make()->title(__('workspaces.actions.stopped'))->success()->send();
                        $this->refreshStatus();
                    }),

                Action::make('delete')
                    ->label(__('workspaces.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => auth()->user()?->can('delete', $this->workspace()) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('workspaces.actions.delete_confirm'))
                    ->action(function (): void {
                        app(WorkspaceService::class)->deleteWorkspace($this->workspace());
                        $this->redirect(WorkspaceResource::getUrl('index'));
                    }),
            ])->icon('heroicon-o-ellipsis-horizontal')->visible($workspace->roleOf(auth()->user()) !== null || (auth()->user()?->isAdmin() ?? false)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $workspace = $this->workspace();

        return [
            'workspace' => $workspace,
            'members' => $workspace->members()->with('user')->get(),
            'transitional' => in_array($workspace->status, [WorkspaceStatus::Provisioning, WorkspaceStatus::Starting, WorkspaceStatus::Stopping], true),
        ];
    }
}
