<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\WorkspaceRole;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Bus;

/**
 * Issue triggers start automatic tasks for the workspace's automation user.
 * Gives a workspace such a user (a member with an agent) and keeps
 * provisioning and the run's steps queued, so ingest tests only see the
 * launch.
 */
trait LaunchesIssueRuns
{
    protected function prepareIssueRuns(Workspace $workspace): User
    {
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoTaskJob::class]);

        $user = User::factory()->create(['name' => 'Issue Bot']);
        AgentCredential::factory()->ownedBy($user)->create();
        WorkspaceMember::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
        $workspace->forceFill(['automation_user_id' => $user->id])->save();

        return $user;
    }
}
