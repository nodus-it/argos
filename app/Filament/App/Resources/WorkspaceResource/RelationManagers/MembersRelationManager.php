<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\RelationManagers;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceMemberService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The people of a workspace and their roles (settings → Members). Every
 * change goes through WorkspaceMemberService, which guards the
 * collaboration gate and keeps the last admin.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('workspaces.settings.members.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('update', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('workspaces.members.user'))
                    ->description(fn (WorkspaceMember $record): string => (string) $record->user->email)
                    ->weight('semibold'),
                TextColumn::make('role')
                    ->label(__('workspaces.members.role'))
                    ->badge()
                    ->formatStateUsing(fn (WorkspaceRole $state): string => $state->label())
                    ->color(fn (WorkspaceRole $state): string => $state->color()),
                TextColumn::make('created_at')
                    ->label(__('workspaces.settings.members.since'))
                    ->since()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading(__('workspaces.settings.members.empty'))
            ->headerActions([
                Action::make('addMember')
                    ->label(__('workspaces.actions.add_member'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => $this->canManage())
                    ->modalDescription(__('workspaces.actions.subscription_hint'))
                    ->schema([
                        Select::make('user_id')
                            ->label(__('workspaces.members.user'))
                            ->options(fn (): array => User::query()
                                ->whereNotIn('id', $this->workspace()->members()->select('user_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        self::roleSelect()->default(WorkspaceRole::Member->value),
                    ])
                    ->action(function (array $data): void {
                        $member = User::query()->findOrFail($data['user_id']);
                        $this->attempt(
                            fn (WorkspaceMemberService $members, User $actor) => $members->add($this->workspace(), $actor, $member, WorkspaceRole::from((string) $data['role'])),
                            __('workspaces.actions.member_added', ['name' => $member->name]),
                        );
                    }),
            ])
            ->recordActions([
                // One "…" menu per row keeps the table narrow at 375px.
                ActionGroup::make([
                    Action::make('changeRole')
                        ->label(__('workspaces.settings.members.change_role'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->visible(fn (): bool => $this->canManage())
                        ->fillForm(fn (WorkspaceMember $record): array => ['role' => $record->role->value])
                        ->schema([self::roleSelect()])
                        ->action(function (WorkspaceMember $record, array $data): void {
                            $this->attempt(
                                fn (WorkspaceMemberService $members) => $members->changeRole($this->workspace(), $record->user, WorkspaceRole::from((string) $data['role'])),
                                __('workspaces.settings.members.role_changed', ['name' => $record->user->name]),
                            );
                        }),
                    Action::make('removeMember')
                        ->label(__('workspaces.settings.members.remove'))
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->visible(fn (): bool => $this->canManage())
                        ->requiresConfirmation()
                        ->modalDescription(__('workspaces.settings.members.remove_confirm'))
                        ->action(function (WorkspaceMember $record): void {
                            $this->attempt(
                                fn (WorkspaceMemberService $members) => $members->remove($this->workspace(), $record->user),
                                __('workspaces.settings.members.removed', ['name' => $record->user->name]),
                            );
                        }),
                ])->visible(fn (): bool => $this->canManage()),
            ]);
    }

    private static function roleSelect(): Select
    {
        return Select::make('role')
            ->label(__('workspaces.members.role'))
            ->options(WorkspaceRole::options())
            ->required()
            ->native(false);
    }

    /** Adding, re-roling and removing need `manageMembers` (admins + collaboration enabled). */
    private function canManage(): bool
    {
        return Auth::user()?->can('manageMembers', $this->workspace()) ?? false;
    }

    /**
     * Run a member change and report it; the service's refusals (last admin,
     * collaboration off) become a danger notification.
     *
     * @param  callable(WorkspaceMemberService, User): mixed  $change
     */
    private function attempt(callable $change, string $success): void
    {
        abort_unless($this->canManage(), 403);
        /** @var User $actor */
        $actor = Auth::user();

        try {
            $change(app(WorkspaceMemberService::class), $actor);
            Notification::make()->title($success)->success()->send();
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    private function workspace(): Workspace
    {
        /** @var Workspace $workspace */
        $workspace = $this->getOwnerRecord();

        return $workspace;
    }
}
