<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkspaceResource\Pages;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Admin\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceMemberService;
use App\Services\Workspace\WorkspaceService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The workspace: chat with Claude on the left, editor and preview on the
 * right. The chat itself is the `workspace.thread-chat` Livewire component.
 */
class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Opening a stopped workspace brings it back (files were kept).
        $workspace = $this->workspace();
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

            ActionGroup::make([
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
