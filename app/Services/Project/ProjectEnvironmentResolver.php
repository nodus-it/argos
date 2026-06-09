<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\RepoProfile;

/**
 * Resolves the project-level environment Argos injects into BOTH the worker
 * (dependency install + quality gates) and the live demo. Two sources are
 * merged into one flat NAME => value map:
 *
 *   1. composer_registries → a single `COMPOSER_AUTH` http-basic JSON blob, so
 *      `composer install` reaches private registries (Private Packagist, Satis,
 *      Flux, Scramble, …) in both places.
 *   2. worker_env → raw NAME/value secrets (DB creds, API keys, or a
 *      hand-written `COMPOSER_AUTH` that then wins over the generated one).
 *
 * Argos-owned keys are dropped so a project secret can never clobber
 * REPO_TOKEN, the agent credential, APP_KEY, the demo's APP_URL, etc.
 */
class ProjectEnvironmentResolver
{
    /**
     * Env names Argos sets itself for the worker and/or the demo. A project's
     * secrets list must not override these.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'PHASE', 'TASK_ID', 'REPO_URL', 'REPO_TOKEN', 'REPO_PLATFORM',
        'BASE_BRANCH', 'AGENT_NAME', 'TASK_DESCRIPTION', 'PHASE_FLAGS',
        'MAX_TURNS', 'CLAUDE_CONFIG_DIR', 'CLAUDE_MODEL', 'LOG_LEVEL',
        'APP_KEY', 'APP_URL', 'ASSET_URL', 'SESSION_COOKIE', 'ARGOS_DEMO',
        'RESUME_SESSION_ID', 'COMMIT_USER_NAME', 'COMMIT_USER_EMAIL',
        'FORCE_UNLOCK', 'CLAUDE_CODE_OAUTH_TOKEN', 'CODEX_AUTH_JSON_CONTENT',
    ];

    /**
     * @return array<string, string>
     */
    public function resolve(RepoProfile $profile): array
    {
        $env = [];

        $composerAuth = $this->composerAuth($profile->composer_registries ?? []);
        if ($composerAuth !== null) {
            $env['COMPOSER_AUTH'] = $composerAuth;
        }

        // Raw secrets layer on top — a hand-written COMPOSER_AUTH overrides the
        // generated one on purpose (the documented escape hatch).
        foreach ($profile->worker_env ?? [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $env[$name] = (string) ($row['value'] ?? '');
        }

        foreach (self::RESERVED as $reserved) {
            unset($env[$reserved]);
        }

        return $env;
    }

    /**
     * Build a Composer `COMPOSER_AUTH` http-basic JSON blob from the structured
     * registry rows. Returns null when no usable row is configured.
     *
     * @param  array<int, array<string, mixed>>  $registries
     */
    private function composerAuth(array $registries): ?string
    {
        $httpBasic = [];

        foreach ($registries as $row) {
            $host = trim((string) ($row['host'] ?? ''));
            $token = (string) ($row['token'] ?? '');
            if ($host === '' || $token === '') {
                continue;
            }
            $username = trim((string) ($row['username'] ?? ''));
            $httpBasic[$host] = [
                'username' => $username !== '' ? $username : 'token',
                'password' => $token,
            ];
        }

        if ($httpBasic === []) {
            return null;
        }

        return json_encode(
            ['http-basic' => $httpBasic],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
