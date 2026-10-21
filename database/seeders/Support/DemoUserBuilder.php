<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;

/**
 * Shared building blocks for the demo profiles: the admin user every profile
 * needs, plus the env-driven Claude agent credential, lifted out of the former DemoSeeder so Basic, Full and Live-Ready
 * can each pull in exactly the pieces they need.
 */
final class DemoUserBuilder
{
    public function __construct(private readonly ?Command $command = null) {}

    /**
     * The single admin user. Idempotent — keyed on SEED_USER_EMAIL (default
     * admin@argos.local) so re-seeding never duplicates it.
     */
    public function adminUser(): User
    {
        $admin = User::firstOrCreate(
            ['email' => (string) Env::get('SEED_USER_EMAIL', 'admin@argos.local')],
            [
                'name' => 'Argos Admin',
                'password' => Hash::make((string) config('argos.admin_password')),
            ],
        );

        // is_admin is not mass-assignable; the seeded account is the admin.
        if (! $admin->isAdmin()) {
            $admin->forceFill(['is_admin' => true])->save();
        }

        return $admin;
    }

    /**
     * Seed the Claude Code agent credential from SEED_CLAUDE_OAUTH_TOKEN (plain
     * claude.ai OAuth token). Returns null when the env var is unset/blank so a
     * production migrate:fresh never lands a demo credential.
     */
    public function claudeFromEnv(string $name = 'demo-seed'): ?AgentCredential
    {
        $token = Env::get('SEED_CLAUDE_OAUTH_TOKEN');

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return AgentCredential::updateOrCreate(
            [
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => $name,
            ],
            [
                'credentials' => ['token' => trim($token)],
                'status' => AgentCredentialStatus::Active->value,
            ],
        );
    }
}
