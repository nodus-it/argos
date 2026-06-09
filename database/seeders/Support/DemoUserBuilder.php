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
 * needs, plus the env-driven agent credentials (Claude OAuth token / Codex
 * auth.json) lifted out of the former DemoSeeder so Basic, Full and Live-Ready
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
        return User::firstOrCreate(
            ['email' => (string) Env::get('SEED_USER_EMAIL', 'admin@argos.local')],
            [
                'name' => 'Argos Admin',
                'password' => Hash::make((string) config('argos.admin_password')),
            ],
        );
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

    /**
     * Seed the Codex agent credential from SEED_CODEX_AUTH_JSON_B64
     * (base64-encoded ~/.codex/auth.json). Returns null when the env var is
     * unset/blank or does not decode to a JSON object.
     */
    public function codexFromEnv(string $name = 'demo-seed'): ?AgentCredential
    {
        $b64 = Env::get('SEED_CODEX_AUTH_JSON_B64');

        if (! is_string($b64) || trim($b64) === '') {
            return null;
        }

        $json = base64_decode(trim($b64), true);

        if ($json === false) {
            $this->command?->warn('SEED_CODEX_AUTH_JSON_B64 is not valid base64 — skipping Codex credential.');

            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $this->command?->warn('SEED_CODEX_AUTH_JSON_B64 does not decode to a JSON object — skipping Codex credential.');

            return null;
        }

        return AgentCredential::updateOrCreate(
            [
                'agent_name' => AgentName::Codex->value,
                'name' => $name,
            ],
            [
                'credentials' => $decoded,
                'status' => AgentCredentialStatus::Active->value,
            ],
        );
    }
}
