<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\ClaudeModel;
use App\Enums\GitProvider;
use App\Enums\PermissionMode;
use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Browser-E2E fixture for pair programming (tests/e2e/workspace.spec.ts):
 * the seeded admin plus a second user, one project, a running workspace with
 * both as members and two tasks on the admin's agent, owned by the admin with
 * the second user invited to both. Fake mode
 * (ARGOS_E2E_FAKE=1) answers the daemon side.
 */
class E2eWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@argos.local')->firstOrFail();
        $pair = User::query()->firstOrCreate(
            ['email' => 'pair@argos.local'],
            ['name' => 'Pair Partner', 'password' => Hash::make((string) config('argos.admin_password'))],
        );

        $agent = AgentCredential::query()->create([
            'user_id' => $admin->id,
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'Admin subscription',
            'credentials' => ['token' => 'oat-e2e-fake'],
            'status' => AgentCredentialStatus::Active,
            'max_concurrent_sessions' => 2,
        ]);

        // No factories: the app image ships without Faker.
        $workspace = new Workspace;
        $workspace->forceFill([
            'slug' => 'e2e-pairing',
            'created_by' => $admin->id,
            'name' => 'E2E pairing',
            'repo_url' => 'https://github.com/test-org/test-repo',
            'token' => 'e2e-fake-token',
            'platform' => GitProvider::GitHub,
            'auth_method' => AuthMethod::Pat,
            'default_branch' => 'main',
            'status' => WorkspaceStatus::Running,
            'daemon_token' => Str::random(64),
            'last_activity_at' => now(),
        ])->save();

        foreach ([[$admin, WorkspaceRole::Admin], [$pair, WorkspaceRole::Member]] as [$user, $role]) {
            WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role, 'added_by' => $admin->id]);
        }

        // Created first so "Pairing" is the most recent task (opened by default).
        $side = new Task;
        $side->forceFill([
            'workspace_id' => $workspace->id,
            'slug' => 'side-quest',
            'owner_user_id' => $admin->id,
            'agent_credential_id' => $agent->id,
            'created_by' => $admin->id,
            'title' => 'Side quest',
            'branch' => 'argos/side-quest',
            'base_branch' => 'main',
            'session_status' => SessionStatus::Closed,
            'permission_mode' => PermissionMode::Default,
            'model' => ClaudeModel::Sonnet46->value,
            'last_seq' => 0,
            'cost_usd' => 0,
            'session_open' => false,
        ])->save();
        $side->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();

        $task = new Task;
        $task->forceFill([
            'workspace_id' => $workspace->id,
            'slug' => 'pairing',
            'owner_user_id' => $admin->id,
            'agent_credential_id' => $agent->id,
            'created_by' => $admin->id,
            'title' => 'Pairing',
            'branch' => 'argos/pairing',
            'base_branch' => 'main',
            'session_status' => SessionStatus::Closed,
            'permission_mode' => PermissionMode::Default,
            'model' => ClaudeModel::Sonnet46->value,
            'last_seq' => 0,
            'cost_usd' => 0,
            'session_open' => false,
        ])->save();

        // Tasks belong to their owner; Pair writes and decides approvals as an invited collaborator.
        foreach ([$side, $task] as $invitedTo) {
            $invitedTo->collaborators()->attach($pair->id, ['invited_by' => $admin->id]);
        }
    }
}
