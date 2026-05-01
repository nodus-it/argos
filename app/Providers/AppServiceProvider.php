<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\AgentConceptCommand;
use App\Console\Commands\AgentDiffCommand;
use App\Console\Commands\AgentImplementCommand;
use App\Console\Commands\AgentPushCommand;
use App\Console\Commands\ArgosCommand;
use App\Domain\Credentials\CredentialStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use PDO;
use PDOException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialStore::class);
    }

    public function boot(): void
    {
        $this->commands([
            ArgosCommand::class,
            AgentConceptCommand::class,
            AgentImplementCommand::class,
            AgentDiffCommand::class,
            AgentPushCommand::class,
        ]);

        $this->configureDatabase();
    }

    private function configureDatabase(): void
    {
        if ($this->canConnectToMariadb()) {
            config(['database.default' => 'mariadb']);
            return;
        }

        $sqlitePath = env('DB_DATABASE', config('argos.config_dir') . '/argos.db');
        config([
            'database.default'                        => 'sqlite',
            'database.connections.sqlite.database'    => $sqlitePath,
        ]);

        $this->ensureSqliteExists($sqlitePath);
        Artisan::call('migrate', ['--force' => true]);
    }

    private function canConnectToMariadb(): bool
    {
        $c   = config('database.connections.mariadb');
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
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        if (! is_file($path)) {
            touch($path);
        }
    }
}
