<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The only writer of `workspace_members`.
 *
 * `add()` is the single enforcement point of the `workspace-collaboration`
 * gate (the EE seam for pair programming): with the gate closed a workspace
 * never gets more than its first admin.
 */
class WorkspaceMemberService
{
    public const COLLABORATION_GATE = 'workspace-collaboration';

    /** Make the creator the first admin; not subject to the collaboration gate. */
    public function addAdmin(Workspace $workspace, User $owner): WorkspaceMember
    {
        return WorkspaceMember::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $owner->id],
            ['role' => WorkspaceRole::Admin, 'added_by' => $owner->id],
        );
    }

    public function collaborationEnabled(User $actor): bool
    {
        return Gate::forUser($actor)->allows(self::COLLABORATION_GATE);
    }

    public function add(Workspace $workspace, User $actor, User $member, WorkspaceRole $role): WorkspaceMember
    {
        if (! $this->collaborationEnabled($actor)) {
            throw WorkspaceException::translated('workspaces.errors.collaboration_disabled');
        }

        // An existing member only changes role — through the last-admin check.
        if (WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $member->id)->exists()) {
            $this->changeRole($workspace, $member, $role);

            return $this->find($workspace, $member);
        }

        return WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => $role,
            'added_by' => $actor->id,
        ]);
    }

    public function changeRole(Workspace $workspace, User $member, WorkspaceRole $role): void
    {
        DB::transaction(function () use ($workspace, $member, $role): void {
            $entry = $this->find($workspace, $member);
            if ($entry->role === WorkspaceRole::Admin && $role !== WorkspaceRole::Admin) {
                $this->assertNotLastAdmin($workspace);
            }
            $entry->update(['role' => $role]);
        });
    }

    public function remove(Workspace $workspace, User $member): void
    {
        DB::transaction(function () use ($workspace, $member): void {
            $entry = $this->find($workspace, $member);
            if ($entry->role === WorkspaceRole::Admin) {
                $this->assertNotLastAdmin($workspace);
            }
            $entry->delete();
        });
    }

    private function find(Workspace $workspace, User $member): WorkspaceMember
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertNotLastAdmin(Workspace $workspace): void
    {
        $admins = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('role', WorkspaceRole::Admin->value)
            ->count();
        if ($admins <= 1) {
            throw WorkspaceException::translated('workspaces.errors.last_admin');
        }
    }
}
