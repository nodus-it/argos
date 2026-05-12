<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\WorkflowStatus;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;

/**
 * Demo-Daten für lokale Smoke-Tests. Nicht in DatabaseSeeder.php registriert —
 * wird ausschließlich explizit über `--seeder=DemoSeeder` aufgerufen (vgl.
 * `.tools/bin/dev-reset.sh`), damit Produktions-Migrate-Fresh keine Demo-
 * Credentials anlegt.
 *
 * Credentials werden nur erzeugt, wenn die zugehörige env-Var gesetzt ist:
 *   SEED_CLAUDE_OAUTH_TOKEN   — plain (claude.ai OAuth-Token)
 *   SEED_CODEX_AUTH_JSON_B64  — base64(`~/.codex/auth.json`)
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => (string) Env::get('SEED_USER_EMAIL', 'admin@argos.local')],
            [
                'name' => 'Argos Admin',
                'password' => Hash::make((string) config('argos.admin_password')),
            ],
        );

        $this->seedClaudeCredential();
        $this->seedCodexCredential();

        $repo = RepoProfile::firstOrCreate(
            ['url' => (string) Env::get('SEED_REPO_URL', 'https://github.com/nodus-it/argos.git')],
            [
                'name' => 'argos (demo)',
                'platform' => GitProvider::GitHub->value,
                'auth_method' => AuthMethod::OAuth->value,
                'default_branch' => 'develop',
                'auto_concept' => false,
                'auto_pr' => false,
            ],
        );

        Task::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Demo task'],
            [
                'repo_profile_id' => $repo->id,
                'description' => 'Seeded by DemoSeeder. Smoke-Test-Task gegen das eigene Repo.',
                'base_branch' => $repo->default_branch,
                'workflow_status' => WorkflowStatus::Draft->value,
            ],
        );
    }

    private function seedClaudeCredential(): void
    {
        $token = Env::get('SEED_CLAUDE_OAUTH_TOKEN');

        if (! is_string($token) || trim($token) === '') {
            return;
        }

        AgentCredential::updateOrCreate(
            [
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => 'demo-seed',
            ],
            [
                'credentials' => ['token' => trim($token)],
                'status' => AgentCredentialStatus::Active->value,
            ],
        );
    }

    private function seedCodexCredential(): void
    {
        $b64 = Env::get('SEED_CODEX_AUTH_JSON_B64');

        if (! is_string($b64) || trim($b64) === '') {
            return;
        }

        $json = base64_decode(trim($b64), true);

        if ($json === false) {
            $this->command?->warn('SEED_CODEX_AUTH_JSON_B64 is not valid base64 — skipping Codex credential.');

            return;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $this->command?->warn('SEED_CODEX_AUTH_JSON_B64 does not decode to a JSON object — skipping Codex credential.');

            return;
        }

        AgentCredential::updateOrCreate(
            [
                'agent_name' => AgentName::Codex->value,
                'name' => 'demo-seed',
            ],
            [
                'credentials' => $decoded,
                'status' => AgentCredentialStatus::Active->value,
            ],
        );
    }
}
