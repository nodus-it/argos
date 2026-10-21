<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\ClaudeModel;
use App\Enums\GitProvider;
use App\Enums\PermissionMode;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Browser-E2E fixture for pair programming (tests/e2e/workspace.spec.ts):
 * the seeded admin plus a second user, one project, a running workspace with
 * both as members and one thread on the admin's agent. Fake mode
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

        // No factories: the app image ships without Faker.
        $profile = RepoProfile::query()->create([
            'name' => 'E2E project',
            'url' => 'https://github.com/test-org/test-repo',
            'token' => 'e2e-fake-token',
            'platform' => GitProvider::GitHub,
            'auth_method' => AuthMethod::Pat,
            'default_branch' => 'main',
        ]);

        $agent = AgentCredential::query()->create([
            'user_id' => $admin->id,
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'Admin subscription',
            'credentials' => ['token' => 'oat-e2e-fake'],
            'status' => AgentCredentialStatus::Active,
            'max_concurrent_sessions' => 2,
        ]);

        $workspace = new Workspace;
        $workspace->forceFill([
            'slug' => 'e2e-pairing',
            'repo_profile_id' => $profile->id,
            'created_by' => $admin->id,
            'name' => 'E2E pairing',
            'base_branch' => 'main',
            'branch' => 'ws/e2e-pairing',
            'status' => WorkspaceStatus::Running,
            'daemon_token' => Str::random(64),
            'last_activity_at' => now(),
        ])->save();

        foreach ([[$admin, WorkspaceRole::Owner], [$pair, WorkspaceRole::Editor]] as [$user, $role]) {
            WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role, 'added_by' => $admin->id]);
        }

        // Created first so "Pairing" is the most recent thread (opened by default).
        $side = new Thread;
        $side->forceFill([
            'workspace_id' => $workspace->id,
            'agent_credential_id' => $agent->id,
            'created_by' => $admin->id,
            'title' => 'Side quest',
            'status' => ThreadStatus::Closed,
            'permission_mode' => PermissionMode::Default,
            'model' => ClaudeModel::Sonnet46->value,
            'last_seq' => 0,
            'cost_usd' => 0,
            'session_open' => false,
        ])->save();
        $side->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();

        $thread = new Thread;
        $thread->forceFill([
            'workspace_id' => $workspace->id,
            'agent_credential_id' => $agent->id,
            'created_by' => $admin->id,
            'title' => 'Pairing',
            'status' => ThreadStatus::Closed,
            'permission_mode' => PermissionMode::Default,
            'model' => ClaudeModel::Sonnet46->value,
            'last_seq' => 0,
            'cost_usd' => 0,
            'session_open' => false,
        ])->save();
    }
}
