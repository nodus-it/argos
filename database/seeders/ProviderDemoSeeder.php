<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\TaskProviderBinding;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;

/**
 * Seeds one demo RepoProfile per git provider and, for every issue provider
 * (GitHub / GitLab / Linear) with a configured ref, both a webhook and a poll
 * TaskProviderBinding with a label filter — so the issue integration can be
 * exercised end-to-end right after a reset.
 *
 * Idempotent (updateOrCreate). OAuth tokens cannot be seeded, so each binding
 * links to an existing ConnectedAccount for its provider when one is present,
 * and otherwise stays account-less — still fully usable via
 * `argos:webhook:simulate` (webhook + ingestion + label matching), only the
 * real poll / write-back paths need the account connected first.
 *
 * Not registered in DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=ProviderDemoSeeder
 * (also invoked at the end of .tools/bin/dev-reset.sh).
 */
final class ProviderDemoSeeder extends Seeder
{
    private const MODES = [TaskProviderMode::Webhook, TaskProviderMode::Poll];

    public function run(): void
    {
        $user = User::where('email', (string) Env::get('SEED_USER_EMAIL', 'admin@argos.local'))->first()
            ?? User::orderBy('id')->first();

        if ($user === null) {
            $this->command?->warn('ProviderDemoSeeder: no user found — run DemoSeeder first. Skipped.');

            return;
        }

        $label = (string) config('argos.provider_demo.label', 'argos-demo');

        $githubProfile = $this->seedGitHub($user, $label);
        $this->seedGitLab($user, $label);
        $this->seedLinear($user, $label, $githubProfile);
    }

    private function seedGitHub(User $user, string $label): ?RepoProfile
    {
        $ref = config('argos.provider_demo.github_ref');
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        $account = $user->connectedAccount('github');
        $profile = $this->upsertProfile(
            name: 'provider-demo (github)',
            platform: GitProvider::GitHub,
            url: "https://github.com/{$ref}.git",
            account: $account,
        );

        $this->upsertBindings($profile, TaskProviderKind::GitHub, $ref, $account, $label);

        return $profile;
    }

    private function seedGitLab(User $user, string $label): void
    {
        $ref = config('argos.provider_demo.gitlab_ref');
        if (! is_string($ref) || $ref === '') {
            $this->command?->info('ProviderDemoSeeder: SEED_GITLAB_ISSUE_REF not set — GitLab demo skipped.');

            return;
        }

        $account = $user->connectedAccount('gitlab');
        $instance = $account?->getInstanceUrl()
            ?? (string) config('argos.provider_demo.gitlab_instance', 'https://gitlab.com');

        $profile = $this->upsertProfile(
            name: 'provider-demo (gitlab)',
            platform: GitProvider::GitLab,
            url: rtrim($instance, '/')."/{$ref}.git",
            account: $account,
        );

        $this->upsertBindings($profile, TaskProviderKind::GitLab, $ref, $account, $label);
    }

    /**
     * Linear has no git repo of its own; its imported issues become tasks
     * against the GitHub demo profile (the closest "work repo").
     */
    private function seedLinear(User $user, string $label, ?RepoProfile $hostProfile): void
    {
        $team = config('argos.provider_demo.linear_team');
        if (! is_string($team) || $team === '') {
            $this->command?->info('ProviderDemoSeeder: SEED_LINEAR_TEAM not set — Linear demo skipped.');

            return;
        }

        if ($hostProfile === null) {
            $this->command?->warn('ProviderDemoSeeder: no host RepoProfile for Linear (configure GitHub first) — Linear skipped.');

            return;
        }

        $account = $user->connectedAccount('linear');
        $this->upsertBindings($hostProfile, TaskProviderKind::Linear, $team, $account, $label);
    }

    private function upsertProfile(
        string $name,
        GitProvider $platform,
        string $url,
        ?ConnectedAccount $account,
    ): RepoProfile {
        return RepoProfile::updateOrCreate(
            ['name' => $name],
            [
                'url' => $url,
                'platform' => $platform->value,
                'default_branch' => 'main',
                'auth_method' => $account !== null ? AuthMethod::OAuth->value : AuthMethod::Pat->value,
                'connected_account_id' => $account?->id,
                'auto_concept' => false,
                'auto_pr' => false,
            ],
        );
    }

    private function upsertBindings(
        RepoProfile $profile,
        TaskProviderKind $kind,
        string $ref,
        ?ConnectedAccount $account,
        string $label,
    ): void {
        foreach (self::MODES as $mode) {
            $existing = TaskProviderBinding::where('repo_profile_id', $profile->id)
                ->where('kind', $kind->value)
                ->where('mode', $mode->value)
                ->first();

            // Webhook bindings need a secret; keep an already-generated one
            // stable across re-seeds so a configured GitHub webhook keeps working.
            $secret = $existing?->webhook_secret;
            if ($mode === TaskProviderMode::Webhook && ($secret === null || $secret === '')) {
                $secret = bin2hex(random_bytes(20));
            }

            TaskProviderBinding::updateOrCreate(
                [
                    'repo_profile_id' => $profile->id,
                    'kind' => $kind->value,
                    'mode' => $mode->value,
                ],
                [
                    'external_project_ref' => $ref,
                    'connected_account_id' => $account?->id,
                    'filters' => ['labels' => [$label]],
                    'webhook_secret' => $secret,
                    'sync_status' => TaskProviderSyncStatus::Active->value,
                ],
            );
        }

        $this->command?->line(sprintf(
            '  seed: %s binding(s) for %s → %s%s',
            $kind->label(),
            $ref,
            $profile->name,
            $account !== null ? ' (account linked)' : ' (no account — simulate only)',
        ));
    }
}
