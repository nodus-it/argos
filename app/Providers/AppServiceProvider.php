<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\RunPhaseJob;
use App\Services\Anthropic\CredentialStore;
use App\Services\GitProvider\BitbucketGitService;
use App\Services\GitProvider\GitHubGitService;
use App\Services\GitProvider\GitLabGitService;
use App\Services\GitProvider\GitProviderRegistry;
use App\Services\IssueTracker\BitbucketIssueTracker;
use App\Services\IssueTracker\GitHubIssueTracker;
use App\Services\IssueTracker\GitLabIssueTracker;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\IssueTracker\LinearIssueTracker;
use App\Workers\Agents\AgentRegistry;
use App\Workers\Agents\ClaudeCodeRunner;
use App\Workers\Agents\CodexRunner;
use App\Workers\Builtin\BuiltinSync;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use PDO;
use PDOException;
use SocialiteProviders\Bitbucket\BitbucketExtendSocialite;
use SocialiteProviders\GitLab\GitLabExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialStore::class);

        $this->app->singleton(GitProviderRegistry::class, function (): GitProviderRegistry {
            $registry = new GitProviderRegistry;

            $registry->register(
                'github',
                fn (string $token, string $instanceUrl): GitHubGitService => new GitHubGitService($token),
            );

            $registry->register(
                'gitlab',
                fn (string $token, string $instanceUrl): GitLabGitService => new GitLabGitService(
                    $token,
                    $instanceUrl ?: 'https://gitlab.com',
                ),
            );

            $registry->register(
                'bitbucket',
                fn (string $token, string $instanceUrl): BitbucketGitService => new BitbucketGitService($token),
            );

            return $registry;
        });

        $this->app->singleton(IssueTrackerRegistry::class, function (): IssueTrackerRegistry {
            $registry = new IssueTrackerRegistry;

            // GitHub and GitLab OAuth scopes ('repo' / 'api') already cover
            // webhook management — no additional scopes need to be requested.
            $registry->register(
                'github',
                fn (string $token, string $instanceUrl): GitHubIssueTracker => new GitHubIssueTracker($token),
            );

            $registry->register(
                'gitlab',
                fn (string $token, string $instanceUrl): GitLabIssueTracker => new GitLabIssueTracker(
                    $token,
                    $instanceUrl ?: 'https://gitlab.com',
                ),
            );

            $registry->register(
                'bitbucket',
                fn (string $token, string $instanceUrl): BitbucketIssueTracker => new BitbucketIssueTracker($token),
            );

            $registry->register(
                'linear',
                fn (string $token, string $instanceUrl): LinearIssueTracker => new LinearIssueTracker($token),
            );

            return $registry;
        });

        $this->app->singleton(AgentRegistry::class, function (): AgentRegistry {
            $registry = new AgentRegistry;

            // Adding a new agent: write the runner class, add a case to
            // App\Enums\AgentName, register here. No DB seeding required.
            $registry->register(ClaudeCodeRunner::class);
            $registry->register(CodexRunner::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, GitLabExtendSocialite::class.'@handle');
        Event::listen(SocialiteWasCalled::class, BitbucketExtendSocialite::class.'@handle');

        Queue::failing(function (JobFailed $event): void {
            if ($event->job->resolveName() === RunPhaseJob::class) {
                return;
            }

            Log::channel('argos')->error('Job failed', [
                'job' => $event->job->resolveName(),
                'error' => $event->exception->getMessage(),
                'class' => $event->exception::class,
            ]);
        });

        // After every `migrate` run, mirror the built-in worker stack manifest
        // into worker_stacks. Skipped during unit tests so RefreshDatabase
        // tests stay fast and free of seed data they didn't ask for; tests
        // that need built-ins call BuiltinSync directly.
        Event::listen(MigrationsEnded::class, function (): void {
            if (app()->runningUnitTests()) {
                return;
            }
            BuiltinSync::default()->sync();
        });

        $this->configureDatabase();
        $this->configurePassport();
    }

    /**
     * Wire up the Passport-backed MCP authentication: declare the single
     * `mcp:use` scope the server requires and, in containerised deploys, load
     * the signing keys from the persistent data volume so issued tokens survive
     * image rebuilds. Locally the keys live in storage/ (passport:install).
     */
    private function configurePassport(): void
    {
        Passport::tokensCan(['mcp:use' => 'Argos via MCP steuern']);
        Passport::tokensExpireIn(now()->addDays(30));
        Passport::refreshTokensExpireIn(now()->addDays(60));

        // Passport 13 ships no default consent screen; reuse the one bundled
        // with laravel/mcp so the first browser OAuth connect renders.
        Passport::authorizationView('mcp::authorize');

        $keysPath = Env::get('PASSPORT_KEYS_PATH');
        if ($keysPath !== null && $keysPath !== '') {
            Passport::loadKeysFrom($keysPath);
        }
    }

    private function configureDatabase(): void
    {
        // If the caller (env, phpunit.xml, .env, …) explicitly chose a connection,
        // honor it without probing or auto-migrating. Auto-detect only when nothing
        // is set — otherwise we burn a 1 s TCP timeout per phpunit boot and risk
        // overriding test config with the SQLite fallback. Env::get() (not env())
        // is used here because we genuinely need to know whether the variable
        // was set rather than the resolved config value.
        if (Env::get('DB_CONNECTION') !== null) {
            return;
        }

        if ($this->canConnectToMariadb()) {
            config(['database.default' => 'mariadb']);

            return;
        }

        $sqlitePath = config('argos.config_dir').'/argos.db';
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $sqlitePath,
        ]);

        $this->ensureSqliteExists($sqlitePath);
        Artisan::call('migrate', ['--force' => true]);
    }

    private function canConnectToMariadb(): bool
    {
        $c = config('database.connections.mariadb');
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_TIMEOUT => 1,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            unset($pdo);

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function ensureSqliteExists(string $path): void
    {
        // Laravel convention: an in-memory database. Don't materialise it on disk.
        if ($path === ':memory:') {
            return;
        }

        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        if (! is_file($path)) {
            touch($path);
        }
    }
}
