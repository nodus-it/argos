<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\Workspace\AdvanceAutoThreadJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

/**
 * Issue triggers start automatic runs for the project's automation user.
 * Gives a project such a user (with an agent) and keeps provisioning and
 * the run's steps queued, so ingest tests only see the launch.
 */
trait LaunchesIssueRuns
{
    protected function prepareIssueRuns(RepoProfile $project): User
    {
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoThreadJob::class]);

        $user = User::factory()->create(['name' => 'Issue Bot']);
        AgentCredential::factory()->ownedBy($user)->create();
        $project->forceFill(['automation_user_id' => $user->id])->save();

        return $user;
    }
}
