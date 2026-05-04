<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('test:providers
    {--reset : Delete all [contract-test] profiles and connected accounts and exit}
    {--seed-only : Only seed profiles, do not run the test suite}
    {--user-email= : Email of the user to attach connected accounts to (default: first user)}')]
#[Description('Interactive helper that seeds three RepoProfiles per provider (PAT + OAuth) and runs the external contract suite against both auth modes')]
class TestProvidersCommand extends Command
{
    private const PROFILE_PREFIX = '[contract-test]';

    /** @var array<int, array{platform: string, key: string, label: string, oauthRedirectPath: string}> */
    private const PROVIDERS = [
        ['platform' => 'github',    'key' => 'GITHUB',    'label' => 'GitHub',    'oauthRedirectPath' => '/auth/github/redirect'],
        ['platform' => 'gitlab',    'key' => 'GITLAB',    'label' => 'GitLab',    'oauthRedirectPath' => '/auth/gitlab/redirect'],
        ['platform' => 'bitbucket', 'key' => 'BITBUCKET', 'label' => 'Bitbucket', 'oauthRedirectPath' => '/auth/bitbucket/redirect'],
    ];

    public function handle(): int
    {
        if ($this->option('reset')) {
            return $this->reset();
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $defaults = $this->loadProviderDefaults();
        if ($defaults === []) {
            $this->error('Konnte tests/External/providers.defaults.php nicht laden.');

            return self::FAILURE;
        }

        // Phase A: PAT-Profile aus .env.testing.external + defaults seeden.
        $patSeeded = $this->seedPatProfiles($defaults);

        // Phase B: User auffordern, OAuth-Accounts manuell zu verbinden.
        $this->newLine();
        $this->info('═══ OAuth-Connect ═══');
        $this->awaitConnectedAccounts($user);

        // Phase C: OAuth-Profile mit FK auf die jetzt vorhandenen ConnectedAccounts seeden.
        $oauthSeeded = $this->seedOauthProfiles($user, $defaults);

        $this->newLine();
        $this->info(sprintf(
            "Gesamt: %d PAT-Profile, %d OAuth-Profile geseedet (Prefix '%s').",
            $patSeeded,
            $oauthSeeded,
            self::PROFILE_PREFIX,
        ));

        if ($this->option('seed-only')) {
            $this->comment('--seed-only gesetzt: keine Tests gestartet.');

            return self::SUCCESS;
        }

        // Phase D: erst die Suite mit PAT-Tokens (env-driven), dann mit OAuth-Tokens (DB-driven).
        $patExit = $this->runSuiteWithEnv([], 'PAT-Tokens (aus .env.testing.external)');

        $oauthEnv = $this->buildOauthEnv($user);
        $oauthExit = $oauthEnv === []
            ? null
            : $this->runSuiteWithEnv($oauthEnv, 'OAuth-Tokens (aus ConnectedAccounts)');

        $this->newLine();
        if ($patExit === 0 && ($oauthExit === null || $oauthExit === 0)) {
            $this->info('Beide Test-Runs grün.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Mindestens ein Test-Run ist fehlgeschlagen (PAT exit=%d, OAuth exit=%s).',
            $patExit,
            $oauthExit === null ? 'übersprungen' : (string) $oauthExit,
        ));

        return self::FAILURE;
    }

    private function reset(): int
    {
        $profiles = RepoProfile::where('name', 'like', self::PROFILE_PREFIX.'%')->get();
        $accountIds = $profiles->pluck('connected_account_id')->filter()->unique()->all();

        $deletedProfiles = $profiles->count();
        foreach ($profiles as $p) {
            $p->delete();
        }

        $deletedAccounts = ConnectedAccount::query()->whereIn('id', $accountIds)->delete();

        $this->info(sprintf(
            'Reset: %d Profile, %d ConnectedAccounts gelöscht.',
            $deletedProfiles,
            $deletedAccounts,
        ));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $email = $this->option('user-email');
        if (is_string($email) && $email !== '') {
            $user = User::where('email', $email)->first();
            if ($user === null) {
                $this->error("Kein User mit email={$email} gefunden.");

                return null;
            }

            return $user;
        }

        $user = User::orderBy('id')->first();
        if ($user === null) {
            $this->error('Kein User in der DB. Lege erst einen Argos-Account an.');

            return null;
        }

        return $user;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadProviderDefaults(): array
    {
        $path = base_path('tests/External/providers.defaults.php');
        if (! is_file($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $defaults
     */
    private function seedPatProfiles(array $defaults): int
    {
        $count = 0;
        foreach (self::PROVIDERS as $provider) {
            $name = self::PROFILE_PREFIX.' '.$provider['platform'].'-pat';
            $token = getenv($provider['key'].'_PAT');
            if ($token === false || $token === '') {
                $this->warn("PAT-Profil {$name}: kein {$provider['key']}_PAT in der Umgebung — übersprungen.");

                continue;
            }

            $coords = $defaults[$provider['platform']] ?? null;
            if (! is_array($coords)) {
                $this->warn("PAT-Profil {$name}: keine Defaults für '{$provider['platform']}' — übersprungen.");

                continue;
            }

            RepoProfile::updateOrCreate(
                ['name' => $name],
                [
                    'url' => $coords['repoCloneUrl'] ?? '',
                    'default_branch' => $coords['defaultBranch'] ?? 'main',
                    'platform' => $provider['platform'],
                    'auth_method' => 'pat',
                    'token' => $token,
                    'connected_account_id' => null,
                    'auto_concept' => false,
                    'auto_pr' => false,
                ],
            );
            $count++;
            $this->line("  seed: {$name}");
        }

        return $count;
    }

    private function awaitConnectedAccounts(User $user): void
    {
        $base = config('app.url') ?: 'http://localhost';

        $this->line('Verbinde im Browser jeweils einen Account pro Provider:');
        foreach (self::PROVIDERS as $provider) {
            $url = rtrim($base, '/').$provider['oauthRedirectPath'];
            $this->line(sprintf('  %s: %s', $provider['label'], $url));
        }
        $this->newLine();

        while (true) {
            $missing = [];
            foreach (self::PROVIDERS as $provider) {
                $exists = ConnectedAccount::query()
                    ->where('user_id', $user->id)
                    ->where('provider', $provider['platform'])
                    ->exists();
                if (! $exists) {
                    $missing[] = $provider['label'];
                }
            }

            if ($missing === []) {
                $this->info('Alle drei ConnectedAccounts sind verbunden.');

                return;
            }

            $this->comment('Noch nicht verbunden: '.implode(', ', $missing));
            if (! $this->confirm('Erneut prüfen?', true)) {
                $this->comment('Abbruch durch User — OAuth-Profile werden für die fehlenden Provider übersprungen.');

                return;
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $defaults
     */
    private function seedOauthProfiles(User $user, array $defaults): int
    {
        $count = 0;
        foreach (self::PROVIDERS as $provider) {
            $account = ConnectedAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider['platform'])
                ->latest('id')
                ->first();
            if ($account === null) {
                continue;
            }

            $coords = $defaults[$provider['platform']] ?? null;
            if (! is_array($coords)) {
                continue;
            }

            $name = self::PROFILE_PREFIX.' '.$provider['platform'].'-oauth';
            RepoProfile::updateOrCreate(
                ['name' => $name],
                [
                    'url' => $coords['repoCloneUrl'] ?? '',
                    'default_branch' => $coords['defaultBranch'] ?? 'main',
                    'platform' => $provider['platform'],
                    'auth_method' => 'oauth',
                    'token' => null,
                    'connected_account_id' => $account->id,
                    'auto_concept' => false,
                    'auto_pr' => false,
                ],
            );
            $count++;
            $this->line("  seed: {$name}");
        }

        return $count;
    }

    /**
     * @return array<string, string>
     */
    private function buildOauthEnv(User $user): array
    {
        $env = [];
        foreach (self::PROVIDERS as $provider) {
            $account = ConnectedAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider['platform'])
                ->latest('id')
                ->first();
            if ($account === null || $account->token === '') {
                continue;
            }
            $env[$provider['key'].'_PAT'] = $account->token;
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $extraEnv
     */
    private function runSuiteWithEnv(array $extraEnv, string $label): int
    {
        $this->newLine();
        $this->info("═══ Test-Run: {$label} ═══");

        $process = new Process(
            ['php', 'artisan', 'test', '--configuration=phpunit.external.xml'],
            base_path(),
            $extraEnv === [] ? null : array_merge(getenv() ?: [], $extraEnv),
            null,
            null,
        );
        $process->setTty(Process::isTtySupported());
        $process->setTimeout(600);
        $process->run(function (string $type, string $buffer): void {
            $this->getOutput()->write($buffer);
        });

        return $process->getExitCode() ?? 1;
    }
}
