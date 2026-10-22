<?php

declare(strict_types=1);

namespace App\Services\Demo;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks a preview's compose file before the manager starts it over the
 * host's Docker socket. The file comes from the repository (or from Claude
 * on a setup branch), so it is untrusted: only an allowlist of service keys
 * passes, volumes must be named, networks private — no privileged mode,
 * capabilities, devices, host paths, host networking, env files, builds or
 * published ports. Every service runs with no-new-privileges and a process
 * limit.
 */
final class DemoComposeGuard
{
    /** Service keys a preview may use. */
    private const SERVICE_KEYS = [
        'image', 'command', 'entrypoint', 'environment', 'working_dir', 'user',
        'depends_on', 'healthcheck', 'networks', 'volumes', 'tmpfs', 'restart',
        'expose', 'stop_grace_period', 'stop_signal', 'init', 'hostname',
    ];

    private const TOP_LEVEL_KEYS = ['services', 'networks', 'volumes'];

    private const PIDS_LIMIT = 512;

    /** The checked compose file, hardened; throws with the reasons otherwise. */
    public function harden(string $composeYaml): string
    {
        try {
            $compose = Yaml::parse($composeYaml);
        } catch (ParseException $e) {
            throw new RuntimeException(DemoConfigLocator::COMPOSE_PATH.' is not valid YAML: '.$e->getMessage());
        }
        if (! is_array($compose) || ! is_array($compose['services'] ?? null) || $compose['services'] === []) {
            throw new RuntimeException(DemoConfigLocator::COMPOSE_PATH.' needs a services: section.');
        }

        $errors = [];
        foreach (array_keys($compose) as $key) {
            if (! in_array($key, self::TOP_LEVEL_KEYS, true) && ! str_starts_with((string) $key, 'x-')) {
                $errors[] = "top-level `{$key}` is not allowed";
            }
        }

        $networks = $this->declared($compose, 'networks', ['internal', 'driver'], $errors);
        $volumes = $this->declared($compose, 'volumes', [], $errors);

        foreach ($compose['services'] as $name => $service) {
            if (! is_array($service)) {
                $errors[] = "service `{$name}` must be a mapping";

                continue;
            }
            foreach (array_keys($service) as $key) {
                if (! in_array($key, self::SERVICE_KEYS, true)) {
                    $errors[] = "service `{$name}`: `{$key}` is not allowed";
                }
            }
            $this->checkVolumes((string) $name, $service['volumes'] ?? [], $volumes, $errors);
            $this->checkNetworks((string) $name, $service['networks'] ?? [], $networks, $errors);

            $compose['services'][$name]['security_opt'] = ['no-new-privileges:true'];
            $compose['services'][$name]['pids_limit'] = self::PIDS_LIMIT;
        }

        if ($errors !== []) {
            throw new RuntimeException('Preview contract refused ('.DemoConfigLocator::COMPOSE_PATH.'): '.implode('; ', $errors).'.');
        }

        return Yaml::dump($compose, 8, 2);
    }

    /**
     * Names of the top-level networks/volumes — plain local ones only (no
     * external ones, no driver options: those can bind host paths).
     *
     * @param  array<string, mixed>  $compose
     * @param  list<string>  $allowedOptions
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function declared(array $compose, string $section, array $allowedOptions, array &$errors): array
    {
        $entries = $compose[$section] ?? [];
        if (! is_array($entries)) {
            $errors[] = "`{$section}` must be a mapping";

            return [];
        }
        foreach ($entries as $name => $options) {
            foreach (is_array($options) ? array_keys($options) : [] as $option) {
                if (! in_array($option, $allowedOptions, true)) {
                    $errors[] = "{$section} `{$name}`: `{$option}` is not allowed";
                }
            }
            if (($options['driver'] ?? 'bridge') !== 'bridge') {
                $errors[] = "{$section} `{$name}`: only the bridge driver is allowed";
            }
        }

        return array_map('strval', array_keys($entries));
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $errors
     */
    private function checkVolumes(string $service, mixed $mounts, array $declared, array &$errors): void
    {
        foreach (is_array($mounts) ? $mounts : [$mounts] as $mount) {
            $source = is_array($mount) ? (($mount['type'] ?? 'volume') === 'volume' ? ($mount['source'] ?? null) : null) : explode(':', (string) $mount)[0];
            if (! is_string($source) || ! in_array($source, $declared, true)) {
                $errors[] = "service `{$service}`: only named volumes declared under volumes: may be mounted";
            }
        }
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $errors
     */
    private function checkNetworks(string $service, mixed $networks, array $declared, array &$errors): void
    {
        if (! is_array($networks)) {
            $errors[] = "service `{$service}`: networks must be a list or mapping";

            return;
        }
        $names = array_is_list($networks) ? $networks : array_keys($networks);
        foreach ($names as $network) {
            if (! in_array((string) $network, $declared, true)) {
                $errors[] = "service `{$service}`: network `{$network}` must be declared under networks: (Argos adds argos_edge itself)";
            }
        }
        foreach (array_is_list($networks) ? [] : $networks as $network => $options) {
            foreach (is_array($options) ? array_keys($options) : [] as $option) {
                if ($option !== 'aliases') {
                    $errors[] = "service `{$service}`: network option `{$option}` is not allowed";
                }
            }
        }
    }
}
