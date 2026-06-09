<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Task;
use App\Services\Project\ProjectEnvironmentResolver;
use Illuminate\Encryption\Encrypter;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the per-task `docker compose` override for a live demo: mounts the task
 * workspace volume into the entry service, joins the Traefik edge network under
 * a unique alias, pins the external URL + a throwaway app key + a per-demo
 * session cookie, and caps resources. Split out of DemoDeployer so the
 * compose-override concern is isolated; the public demo URL is computed by
 * TraefikRouter and handed in.
 */
class DemoComposeBuilder
{
    /**
     * Generate the per-task compose override. Deliberately carries NO Traefik
     * labels — routing is done via the file provider, not the docker provider.
     *
     * @param  array{service: string, port: int, workspace_mount: string}  $entry
     * @param  string  $demoUrl  the public URL (incl. external port) the demo is
     *                           reachable under, from TraefikRouter::urlForSlug()
     */
    public function buildOverrideYaml(Task $task, string $slug, array $entry, string $demoUrl): string
    {
        $network = (string) config('argos.preview.network', 'argos_edge');

        // Project-level secrets (COMPOSER_AUTH for private registries, DB creds,
        // API keys, …) so the demo's `composer install` + boot see the same env
        // as the worker. Argos-owned keys below win via array_merge order, so
        // the project can't override APP_URL / APP_KEY / SESSION_COOKIE here.
        $projectEnv = $task->repoProfile !== null
            ? app(ProjectEnvironmentResolver::class)->resolve($task->repoProfile)
            : [];

        $override = [
            'services' => [
                $entry['service'] => [
                    // The container only sees the internal port (80); it has no
                    // idea Traefik publishes it on the external port. Without
                    // this, Laravel/Vite generate asset URLs from the request
                    // host WITHOUT the external port → the browser fetches CSS/JS
                    // on :80 and fails. Pin the full external URL so asset()/
                    // url()/Vite emit reachable links. (Harmless for non-Laravel
                    // contracts — they ignore these env vars.)
                    'environment' => array_merge($projectEnv, [
                        'APP_URL' => $demoUrl,
                        'ASSET_URL' => $demoUrl,
                        // Inject a throwaway app key so Laravel boots even when
                        // the repo's .env.example ships no APP_KEY= line for
                        // `key:generate` to fill (Argos itself is such a repo →
                        // MissingAppKeyException → every request 500s). Laravel
                        // reads real env over the repo .env, so this wins; a
                        // fresh per-deploy key is fine for an ephemeral demo.
                        'APP_KEY' => $this->generateAppKey(),
                        // Per-demo session cookie name. Each demo runs on its own
                        // subdomain under the shared parent domain; the parent app
                        // sets a leading-dot `.{domain}` cookie (`argos_session`)
                        // that spans the demo subdomain. If the demo is itself an
                        // Argos instance it would otherwise reuse that name, the
                        // browser would send the parent's cookie, the demo couldn't
                        // decrypt it (different APP_KEY) and would reset the session
                        // every request → login never persists. A per-slug name
                        // sidesteps it; non-Laravel contracts ignore the var.
                        'SESSION_COOKIE' => $this->demoCookieName($slug),
                        // Mark this container as an Argos live demo. Argos' own
                        // DatabaseSeeder reads it (config argos.demo.enabled) to
                        // seed the full demo profile instead of the production-safe
                        // admin-only seed. Harmless for any other repo.
                        'ARGOS_DEMO' => '1',
                    ]),
                    'volumes' => [
                        $task->volumeName().':'.$entry['workspace_mount'],
                    ],
                    'networks' => [
                        $network => [
                            'aliases' => [$slug],
                        ],
                    ],
                    'deploy' => [
                        'resources' => [
                            'limits' => [
                                'cpus' => (string) config('argos.preview.cpu_limit', '1.0'),
                                'memory' => (string) config('argos.preview.memory_limit', '1g'),
                            ],
                        ],
                    ],
                ],
            ],
            'networks' => [
                $network => [
                    'external' => true,
                ],
            ],
            // The task workspace volume is created by the worker and pre-exists;
            // declare it external so compose mounts it instead of erroring on an
            // "undefined volume".
            'volumes' => [
                $task->volumeName() => [
                    'external' => true,
                ],
            ],
        ];

        return Yaml::dump($override, 6, 2);
    }

    /**
     * A valid throwaway Laravel APP_KEY for the demo container — mirrors what
     * `php artisan key:generate` produces, but without depending on the repo
     * shipping an APP_KEY= line in .env(.example).
     */
    private function generateAppKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
    }

    /**
     * A demo-unique session cookie name derived from the DNS-safe slug. Stable
     * across requests of one demo (so the session persists) and distinct from
     * both the parent app's `argos_session` and every other demo's cookie.
     */
    private function demoCookieName(string $slug): string
    {
        return str_replace('-', '_', $slug).'_session';
    }
}
