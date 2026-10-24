<?php

declare(strict_types=1);

namespace App\Services\Workspace\Environment;

use App\Models\Workspace;
use App\Services\Demo\DemoConfigLocator;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Which `.env.example` keys a demo gets filled (by Argos, the example or the
 * workspace) and which stay open; `.argos/demo.yml` may add `env.required` and `login`.
 */
class EnvAudit
{
    /** Keys Argos fills for demos and worktrees itself. */
    private const ARGOS_KEYS = ['APP_KEY', 'APP_URL', 'ASSET_URL', 'SESSION_COOKIE', 'ARGOS_DEMO', 'CACHE_PREFIX'];

    private const ARGOS_PREFIXES = ['DB_', 'REDIS_'];

    public function __construct(
        private readonly GitServiceFactory $git,
        private readonly EnvironmentResolver $environment,
    ) {}

    /**
     * `.env.example` at the setup ref, cached briefly.
     *
     * @return array<string, string>
     */
    public function example(Workspace $workspace): array
    {
        return $this->cached($workspace, 'example', fn (): array => self::parse((string) $this->file($workspace, '.env.example')));
    }

    /**
     * `.argos/demo.yml` at the setup ref, cached briefly; empty when missing or invalid.
     *
     * @return array<string, mixed>
     */
    public function demoSettings(Workspace $workspace): array
    {
        return $this->cached($workspace, 'demo', function () use ($workspace): array {
            try {
                $settings = Yaml::parse((string) $this->file($workspace, DemoConfigLocator::SETTINGS_PATH));
            } catch (Throwable) {
                return [];
            }

            return is_array($settings) ? $settings : [];
        });
    }

    /**
     * Empty keys nobody fills (`open`) and required keys still without a value (`missing`).
     *
     * @return array{open: list<string>, missing: list<string>}
     */
    public function audit(Workspace $workspace): array
    {
        $example = $this->example($workspace);
        $configured = $this->environment->resolve($workspace);
        $filled = fn (string $key): bool => self::setByArgos($key) || ($example[$key] ?? '') !== '' || ($configured[$key] ?? '') !== '';

        $required = array_values(array_filter(array_map('strval', (array) data_get($this->demoSettings($workspace), 'env.required', []))));

        return [
            'open' => array_values(array_filter(array_keys($example), fn (string $key): bool => ! $filled($key))),
            'missing' => array_values(array_filter($required, fn (string $key): bool => ! $filled($key))),
        ];
    }

    /**
     * How to log into the demo, from demo.yml `login`.
     *
     * @return array{path: string, email: string, password: string}|null
     */
    public function login(Workspace $workspace): ?array
    {
        $login = data_get($this->demoSettings($workspace), 'login');
        if (! is_array($login) || ! isset($login['email'], $login['password'])) {
            return null;
        }

        return ['path' => (string) ($login['path'] ?? '/login'), 'email' => (string) $login['email'], 'password' => (string) $login['password']];
    }

    /** The database driver the repo expects when it is one our MySQL sidecar speaks. */
    public function databaseDriver(Workspace $workspace): string
    {
        $driver = $this->example($workspace)['DB_CONNECTION'] ?? '';

        return in_array($driver, ['mysql', 'mariadb'], true) ? $driver : 'mysql';
    }

    public static function setByArgos(string $key): bool
    {
        if (in_array($key, self::ARGOS_KEYS, true)) {
            return true;
        }

        foreach (self::ARGOS_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `KEY=value` lines of a dotenv file; quotes and trailing comments stripped.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $env = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = trim($m[2]);
            if (preg_match('/^(["\'])(.*)\1/', $value, $q) === 1) {
                $value = $q[2];
            } else {
                $value = trim((string) preg_replace('/(^|\s+)#.*$/', '', $value));
            }
            $env[$m[1]] = $value;
        }

        return $env;
    }

    private function file(Workspace $workspace, string $path): ?string
    {
        try {
            return $this->git->forWorkspace($workspace)->getFileContents($workspace->getOwnerRepo(), $path, $workspace->setupRef());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $resolve
     * @return T
     */
    private function cached(Workspace $workspace, string $what, callable $resolve): mixed
    {
        return Cache::remember('ws-env-audit:'.$what.':'.$workspace->id.':'.$workspace->setupRef(), 300, $resolve);
    }
}
