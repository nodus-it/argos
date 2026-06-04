<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\WorkflowStatus;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\Support\DemoUserBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;

/**
 * Live-Ready profile: real OAuth fully wired so a REAL task can run immediately.
 * LOCAL ONLY — skipped outside the local environment. Secrets are read from the
 * root .env (bind-mounted into the app container); nothing sensitive is
 * committed.
 *
 * Required env (each missing/unset → warn + skip cleanly, never throw):
 *   SEED_GITHUB_OAUTH_TOKEN   — GitHub OAuth access token (the live git token)
 *   SEED_REPO_URL             — repo to run against, e.g. https://github.com/nodus-it/argos.git
 *   SEED_CLAUDE_OAUTH_TOKEN   — claude.ai OAuth token for the Claude Code agent
 * Optional env:
 *   SEED_GITHUB_REFRESH_TOKEN — GitHub OAuth refresh token
 *   SEED_GITHUB_USER          — GitHub login (provider_id / name / nickname)
 *   SEED_REPO_BRANCH          — default branch + task base branch (default: main)
 *   SEED_USER_EMAIL           — admin email (default: admin@argos.local)
 *
 * The ConnectedAccount is seeded with expires_at=null so TokenRefresher never
 * fires — the seeded token is used verbatim for an immediate real run.
 *
 * Run via `composer dev:live` (→ .tools/bin/dev-reset.sh live) or
 * `php artisan db:seed --class=LiveReadySeeder`.
 */
final class LiveReadySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LiveReadySeeder is local-only — skipped (APP_ENV='.app()->environment().').');

            return;
        }

        $user = (new DemoUserBuilder($this->command))->adminUser();

        $githubToken = $this->requiredEnv('SEED_GITHUB_OAUTH_TOKEN');
        $repoUrl = $this->requiredEnv('SEED_REPO_URL');
        $claude = (new DemoUserBuilder($this->command))->claudeFromEnv('live');

        if ($githubToken === null || $repoUrl === null || $claude === null) {
            $this->command?->warn('LiveReadySeeder: missing SEED_GITHUB_OAUTH_TOKEN / SEED_REPO_URL / SEED_CLAUDE_OAUTH_TOKEN — live task not wired (admin user kept).');

            return;
        }

        $branch = $this->env('SEED_REPO_BRANCH') ?? 'main';
        $login = $this->env('SEED_GITHUB_USER') ?? 'argos-live';

        $account = $this->connectedAccount($user, $githubToken, $login);
        $profile = $this->liveProfile($account, $repoUrl, $branch);
        $this->liveTask($user, $profile, $branch);

        $this->command?->info('Live-Ready profile seeded: GitHub OAuth account + repo + Claude credential + one runnable Draft task.');
    }

    private function connectedAccount(User $user, string $token, string $login): ConnectedAccount
    {
        return ConnectedAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'github'],
            [
                'provider_id' => $login,
                'token' => $token,
                'refresh_token' => $this->env('SEED_GITHUB_REFRESH_TOKEN'),
                // null ⇒ TokenRefresher::needsRefresh() false ⇒ token used verbatim.
                'expires_at' => null,
                'name' => $login,
                'nickname' => $login,
                'instance_url' => '',
            ],
        );
    }

    private function liveProfile(ConnectedAccount $account, string $repoUrl, string $branch): RepoProfile
    {
        return RepoProfile::updateOrCreate(
            ['name' => 'argos (live)'],
            [
                'url' => $repoUrl,
                'platform' => GitProvider::GitHub->value,
                'auth_method' => AuthMethod::OAuth->value,
                'connected_account_id' => $account->id,
                'default_branch' => $branch,
                'auto_concept' => false,
                'auto_pr' => false,
            ],
        );
    }

    private function liveTask(User $user, RepoProfile $profile, string $branch): void
    {
        Task::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Live demo task'],
            [
                'repo_profile_id' => $profile->id,
                'description' => 'Seeded by LiveReadySeeder — ready to run against the real repo.',
                'base_branch' => $branch,
                'workflow_status' => WorkflowStatus::Draft->value,
            ],
        );
    }

    private function env(string $key): ?string
    {
        $value = Env::get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function requiredEnv(string $key): ?string
    {
        return $this->env($key);
    }
}
