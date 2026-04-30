<?php

declare(strict_types=1);

namespace App\Providers;

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
        $this->configureDatabase();
    }

    private function configureDatabase(): void
    {
        $store = $this->app->make(CredentialStore::class);
        $dbConfig = $store->getDbConfig();

        if ($dbConfig !== null) {
            // Apply db.env values to the mariadb connection config dynamically
            $map = [
                'ARGOS_DB_HOST' => 'host',
                'ARGOS_DB_PORT' => 'port',
                'ARGOS_DB_DATABASE' => 'database',
                'ARGOS_DB_USERNAME' => 'username',
                'ARGOS_DB_PASSWORD' => 'password',
            ];

            foreach ($map as $envKey => $configKey) {
                if (isset($dbConfig[$envKey])) {
                    config(["database.connections.mariadb.{$configKey}" => $dbConfig[$envKey]]);
                }
            }
        }

        if ($this->canConnectToMariadb()) {
            config(['database.default' => 'mariadb']);
            return;
        }

        $sqlitePath = config('argos.config_dir') . '/argos.db';
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
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        if (!is_file($path)) {
            touch($path);
        }
    }
}
