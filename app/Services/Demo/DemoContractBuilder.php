<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\BackingService;
use App\Models\RepoProfile;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the bundled default demo contract for a Laravel repo that ships no
 * `.argos/demo.*`. Starts from the stub compose and unifies it with the
 * project's backing-service config: the demo DB uses the same configured
 * credentials as the worker sidecar, and Redis is added when the project
 * enabled it. A repo that ships its own `.argos/demo.compose.yml` keeps full
 * control and is not touched.
 */
class DemoContractBuilder
{
    /**
     * @return array{0: string, 1: string} [composeYaml, settingsYaml]
     */
    public function buildDefault(RepoProfile $profile): array
    {
        $dir = resource_path('stubs/demo/laravel');
        $settingsYaml = (string) file_get_contents($dir.'/demo.yml');

        $compose = Yaml::parse((string) file_get_contents($dir.'/demo.compose.yml'));
        if (! is_array($compose)) {
            $compose = [];
        }

        $this->applyMysqlCredentials($compose, $profile);
        $this->applyRedis($compose, $profile);

        return [Yaml::dump($compose, 8, 2), $settingsYaml];
    }

    /**
     * Override the demo DB credentials with the profile's configured ones (if
     * any), keeping the stub defaults otherwise — so a name the project
     * hardcodes is honoured in both the worker and the demo.
     *
     * @param  array<string, mixed>  $compose
     */
    private function applyMysqlCredentials(array &$compose, RepoProfile $profile): void
    {
        $config = $profile->worker_service_config['mysql'] ?? null;
        if (! is_array($config)) {
            return;
        }

        $database = trim((string) ($config['database'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));
        $password = trim((string) ($config['password'] ?? ''));

        if ($database !== '') {
            data_set($compose, 'services.db.environment.MARIADB_DATABASE', $database);
            data_set($compose, 'services.app.environment.DB_DATABASE', $database);
        }
        if ($username !== '') {
            data_set($compose, 'services.db.environment.MARIADB_USER', $username);
            data_set($compose, 'services.app.environment.DB_USERNAME', $username);
        }
        if ($password !== '') {
            data_set($compose, 'services.db.environment.MARIADB_PASSWORD', $password);
            data_set($compose, 'services.db.environment.MARIADB_ROOT_PASSWORD', $password);
            data_set($compose, 'services.app.environment.DB_PASSWORD', $password);
        }
    }

    /**
     * Add a Redis service (+ the app's REDIS_HOST/PORT env and a healthy
     * dependency) when the project enabled Redis — the bundled stub ships none.
     *
     * @param  array<string, mixed>  $compose
     */
    private function applyRedis(array &$compose, RepoProfile $profile): void
    {
        if (! in_array(BackingService::Redis, $profile->backingServices(), true)) {
            return;
        }

        data_set($compose, 'services.redis', [
            'image' => BackingService::Redis->image(),
            'networks' => ['demo-internal'],
            'healthcheck' => [
                'test' => ['CMD', 'redis-cli', 'ping'],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 30,
            ],
        ]);

        $appEnv = (array) data_get($compose, 'services.app.environment', []);
        data_set($compose, 'services.app.environment', array_merge(
            $appEnv,
            BackingService::Redis->connectionEnv(BackingService::Redis->defaultCoordinates()),
        ));
        data_set($compose, 'services.app.depends_on.redis', ['condition' => 'service_healthy']);
    }
}
