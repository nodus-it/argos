<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\BackingService;
use App\Models\RepoProfile;

/**
 * Single source of truth for a project's backing-service coordinates. Merges
 * each enabled service's fixed defaults (host/port) with the profile's
 * credential overrides (database/username/password), and exposes them three
 * ways: as resolved coordinates, as standard connection env (DB_HOST, …) for
 * auto-injection, and as a `${mysql.host}`-style placeholder map so a project
 * with non-standard env names never has to hardcode internal values.
 *
 * Used by the worker (WorkerSidecarManager + ProjectEnvironmentResolver) and
 * the live demo, so both boot the same service definition.
 */
class BackingServiceResolver
{
    /**
     * Resolved coordinates per enabled service: `['mysql' => ['host' => 'db',
     * 'database' => …, …], …]`.
     *
     * @return array<string, array<string, string>>
     */
    public function coordinates(RepoProfile $profile): array
    {
        $config = $profile->worker_service_config ?? [];
        $out = [];

        foreach ($profile->backingServices() as $service) {
            $coords = $service->defaultCoordinates();
            $override = is_array($config[$service->value] ?? null) ? $config[$service->value] : [];

            foreach ($service->configurableKeys() as $key) {
                $value = isset($override[$key]) ? trim((string) $override[$key]) : '';
                if ($value !== '') {
                    $coords[$key] = $value;
                }
            }

            $out[$service->value] = $coords;
        }

        return $out;
    }

    /**
     * Standard connection env (DB_HOST, DB_DATABASE, REDIS_HOST, …) for every
     * enabled service — auto-injected so standard-name projects need zero config.
     *
     * @return array<string, string>
     */
    public function connectionEnv(RepoProfile $profile): array
    {
        $env = [];
        foreach ($this->coordinates($profile) as $key => $coords) {
            $env = array_merge($env, BackingService::from($key)->connectionEnv($coords));
        }

        return $env;
    }

    /**
     * Placeholder map for expansion in user-supplied env values:
     * `['${mysql.host}' => 'db', '${mysql.database}' => 'argos', …]`.
     *
     * @return array<string, string>
     */
    public function placeholders(RepoProfile $profile): array
    {
        $map = [];
        foreach ($this->coordinates($profile) as $key => $coords) {
            foreach ($coords as $coordKey => $value) {
                $map['${'.$key.'.'.$coordKey.'}'] = $value;
            }
        }

        return $map;
    }
}
