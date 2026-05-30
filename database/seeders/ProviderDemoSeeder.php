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
 * Seeds a complete demo matrix for the provider integrations so everything is
 * testable end-to-end right after a reset:
 *
 *   - one demo RepoProfile per GIT provider — GitHub, GitLab, Bitbucket;
 *   - one (webhook + poll) TaskProviderBinding per ISSUE provider — GitHub on
 *     its own profile, GitLab on its own profile, and Linear (which has no git
 *     repo) hung off the Bitbucket profile.
 *
 * So every git provider has a repo profile and every task provider has a
 * binding. Demo refs default to the committed coordinates in
 * tests/External/providers.defaults.php; the SEED_*_REF / SEED_GITLAB_INSTANCE
 * env vars override them (e.g. to point GitLab at a self-hosted instance), and
 * SEED_LINEAR_TEAM supplies the Linear team key (no committed default).
 *
 * Idempotent (updateOrCreate). OAuth tokens cannot be seeded, so each binding
 * links to an existing ConnectedAccount for its provider when one is present
 * and otherwise stays account-less — still usable via `argos:webhook:simulate`
 * (webhook + ingestion + label matching); only real poll / write-back need the
 * account connected first.
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
        $defaults = $this->loadDefaults();

        $githubRef = $this->resolveRef('github', $defaults);
        $gitlabRef = $this->resolveRef('gitlab', $defaults);
        $bitbucketRef = $this->resolveRef('bitbucket', $defaults);

        // Git providers → one demo repo profile each.
        $githubProfile = $githubRef !== null ? $this->seedGitProfile($user, GitProvider::GitHub, 'github', $githubRef, $defaults) : null;
        $gitlabProfile = $gitlabRef !== null ? $this->seedGitProfile($user, GitProvider::GitLab, 'gitlab', $gitlabRef, $defaults) : null;
        $bitbucketProfile = $bitbucketRef !== null ? $this->seedGitProfile($user, GitProvider::Bitbucket, 'bitbucket', $bitbucketRef, $defaults) : null;

        // Issue providers → bindings. GitHub and GitLab on their own profiles…
        if ($githubProfile !== null) {
            $this->seedBindings($githubProfile, TaskProviderKind::GitHub, $githubRef, $user->connectedAccount('github'), $label);
        }
        if ($gitlabProfile !== null) {
            $this->seedBindings($gitlabProfile, TaskProviderKind::GitLab, $gitlabRef, $user->connectedAccount('gitlab'), $label);
        }

        // …and Linear (no git repo of its own) on the Bitbucket profile, so
        // every task provider is covered.
        $this->seedLinear($user, $label, $bitbucketProfile, $defaults);
    }

    /**
     * @param  array<string, array<string, mixed>>  $defaults
     */
    private function seedGitProfile(User $user, GitProvider $platform, string $key, string $ref, array $defaults): RepoProfile
    {
        $account = $user->connectedAccount($key);
        $instance = $this->instanceFor($key, $defaults);
        $url = rtrim($instance, '/')."/{$ref}.git";

        $profile = RepoProfile::updateOrCreate(
            ['name' => "provider-demo ({$key})"],
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

        return $profile;
    }

    /**
     * @param  array<string, array<string, mixed>>  $defaults
     */
    private function seedLinear(User $user, string $label, ?RepoProfile $bitbucketProfile, array $defaults): void
    {
        $override = config('argos.provider_demo.linear_team');
        $team = is_string($override) && $override !== ''
            ? $override
            : ($defaults['linear']['team'] ?? null);

        if (! is_string($team) || $team === '') {
            $this->command?->info('ProviderDemoSeeder: no Linear team (SEED_LINEAR_TEAM / providers.defaults.php) — Linear demo skipped.');

            return;
        }

        if ($bitbucketProfile === null) {
            $this->command?->warn('ProviderDemoSeeder: no Bitbucket profile to host the Linear binding — Linear skipped.');

            return;
        }

        $this->seedBindings($bitbucketProfile, TaskProviderKind::Linear, $team, $user->connectedAccount('linear'), $label);
    }

    private function seedBindings(
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
            // stable across re-seeds so a configured webhook keeps working.
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

    /**
     * Resolve a provider's demo ref: the SEED_*_REF env override wins, else the
     * committed coordinates in providers.defaults.php, else null (skip).
     *
     * @param  array<string, array<string, mixed>>  $defaults
     */
    private function resolveRef(string $key, array $defaults): ?string
    {
        $override = config("argos.provider_demo.{$key}_ref");
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $coords = $defaults[$key] ?? null;
        if (is_array($coords) && isset($coords['testRepoOwner'], $coords['testRepo'])) {
            return $coords['testRepoOwner'].'/'.$coords['testRepo'];
        }

        return null;
    }

    /**
     * The host for a provider's clone URL, taken from the committed entry. Only
     * GitLab is instance-variable: the SEED_GITLAB_INSTANCE override wins, else
     * the entry's instanceUrl (gitlab.com for the demo — we don't run a second
     * self-hosted GitLab for tests). GitHub and Bitbucket are their public
     * hosts. The account's getInstanceUrl() is deliberately NOT used here — it
     * defaults to gitlab.com for non-GitLab accounts and would point a demo at
     * the wrong host.
     *
     * @param  array<string, array<string, mixed>>  $defaults
     */
    private function instanceFor(string $key, array $defaults): string
    {
        if ($key === 'github') {
            return 'https://github.com';
        }
        if ($key === 'bitbucket') {
            return 'https://bitbucket.org';
        }

        // gitlab
        $override = config('argos.provider_demo.gitlab_instance');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return (string) ($defaults['gitlab']['instanceUrl'] ?? 'https://gitlab.com');
    }

    /**
     * Load the committed provider coordinates (dev-only file). Returns [] when
     * the file is absent (e.g. tests excluded from a deploy) so the seeder then
     * relies purely on the SEED_*_REF env overrides.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadDefaults(): array
    {
        $path = base_path('tests/External/providers.defaults.php');
        if (! is_file($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) ? $data : [];
    }
}
