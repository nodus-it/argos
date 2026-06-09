<?php

declare(strict_types=1);

/*
 * Single source of truth for the running app version. The release workflow
 * (.github/workflows/release.yml) rewrites the literal below with `sed` and
 * commits it together with the CHANGELOG entry, so the value here always
 * matches the git tag that ships the same commit. Local/develop builds keep
 * '0.0.0-dev' and use floating worker tags (no version pinning).
 */
$argosVersion = '0.1.0-beta.4';

// APP_URL is the single source of truth for host + scheme; the live-demo
// base domain and scheme derive from it (override only for an exotic setup
// where demos live on a different domain than the app).
$appUrl = (string) env('APP_URL', 'http://localhost');
$appHost = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
$appScheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
// Bare localhost / IPs have no usable wildcard DNS; nip.io gives zero-config
// wildcard subdomains locally (*.127.0.0.1.nip.io → 127.0.0.1).
$bareHost = $appHost === 'localhost'
    || filter_var($appHost, FILTER_VALIDATE_IP) !== false
    || ! str_contains($appHost, '.');

// Treat an empty-string env var as "unset". The canonical docker-compose.yml
// forwards the whole preview block as empty `${VAR:-}` on purpose ("everything
// else is forwarded empty so the defaults in config/argos.php apply"), but
// Laravel's env('X', $default) returns '' — not $default — when the var is
// set-but-empty. Without this, an empty default_image yields a `:hash` tag and
// `docker build` fails; ttl_hours/port/network/etc. silently collapse to 0/''.
$envOr = fn (string $key, mixed $default): mixed => (($v = env($key)) === null || $v === '') ? $default : $v;

return [
    // env() wins so CI can bake a `stage-<date>-<sha>` version into the
    // :stage image at build time; `?:` (not the 2nd arg) so an *empty*
    // ARGOS_VERSION — what release images carry — falls back to the literal.
    'version' => env('ARGOS_VERSION') ?: $argosVersion,
    /*
     * AGPL-3.0 §13 source-offer URL. Every running instance must point users
     * to the corresponding source. For unmodified deployments this is the
     * upstream repo; forks must override this with their own source URL via
     * ARGOS_SOURCE_URL.
     */
    'source_url' => env('ARGOS_SOURCE_URL', 'https://github.com/nodus-it/argos'),
    'config_dir' => env('ARGOS_CONFIG_DIR', (getenv('HOME') ?: ($_SERVER['HOME'] ?? posix_getpwuid(posix_getuid())['dir'])).'/.config/argos'),
    'admin_password' => env('ADMIN_PASSWORD') ?: '12345',
    /*
     * Local one-click developer login (filament-developer-logins). Only ever
     * active in the `local` environment — the AdminPanelProvider gates the
     * plugin on app()->environment('local'), so this email is meaningless in
     * staging/production. Matches the demo seeders' admin so the button works
     * out of the box after a dev reset.
     */
    'dev_login_email' => env('SEED_USER_EMAIL', 'admin@argos.local'),
    /*
     * How often (minutes) the scheduler polls issue providers and checks
     * concept-comment reactions. Default 5 keeps API usage low at scale; set
     * ARGOS_POLL_INTERVAL_MINUTES=1 locally for fast feedback. Clamped to 1–59.
     */
    'poll_interval_minutes' => max(1, min(59, (int) env('ARGOS_POLL_INTERVAL_MINUTES', 5))),
    /*
     * Demo task-provider bindings seeded by ProviderMatrixBuilder (FullDemoSeeder) for local
     * end-to-end testing of the issue integration. These env vars only
     * OVERRIDE the committed defaults in tests/External/providers.defaults.php
     * (GitHub/GitLab/Bitbucket repos + the Linear team), read dev-only by the
     * seeder. Set SEED_GITLAB_ISSUE_REF (+ optionally SEED_GITLAB_INSTANCE) to
     * point GitLab at a self-hosted instance instead of the gitlab.com default.
     * Never read outside seeding.
     */
    'provider_demo' => [
        'label' => env('SEED_PROVIDER_DEMO_LABEL', 'argos-demo'),
        'github_ref' => env('SEED_GITHUB_ISSUE_REF') ?: null,
        'gitlab_ref' => env('SEED_GITLAB_ISSUE_REF') ?: null,
        'gitlab_instance' => env('SEED_GITLAB_INSTANCE') ?: null,
        'bitbucket_ref' => env('SEED_BITBUCKET_REF') ?: null,
        'linear_team' => env('SEED_LINEAR_TEAM') ?: null,
    ],
    'docker' => [
        'memory_limit' => env('ARGOS_MEM_LIMIT', '4g'),
        'cpu_limit' => env('ARGOS_CPU_LIMIT', '2'),
    ],
    /*
     * Backing services Argos can boot alongside a worker phase run as ephemeral
     * sidecars (one private network per run, torn down afterwards) so a
     * project's tests can talk to a real MySQL/Redis. A repo profile opts in per
     * service; only the test-running phases start them. See
     * App\Enums\BackingService and App\Services\Workflow\WorkerSidecarManager.
     */
    'worker' => [
        'services' => [
            'startup_timeout' => (int) env('ARGOS_WORKER_SERVICE_TIMEOUT', 60),
            'mysql' => [
                'image' => env('ARGOS_WORKER_MYSQL_IMAGE', 'mariadb:11'),
            ],
            'redis' => [
                'image' => env('ARGOS_WORKER_REDIS_IMAGE', 'redis:7-alpine'),
            ],
        ],
    ],
    /*
     * Compose-pipeline settings. The WorkerImageResolver consults
     * `compose.default_stack` when neither the task nor the repo profile
     * pins a stack; this is the slug of a row in `worker_stacks`, populated
     * from the built-in manifest by BuiltinSync on every `migrate`.
     */
    'compose' => [
        'default_stack' => env('ARGOS_DEFAULT_STACK', 'php-8.4'),
    ],

    // Live-Demo (P6): ephemeral per-task demo deployments, routed by Traefik
    // under their own subdomain. See docs/backlog/live-demo-concept.md.
    'preview' => [
        // On by default — a project's own `live_demo_enabled` toggle is the real
        // per-project gate, so this only governs whether the platform *can*
        // deploy demos at all. Operators without preview infra (Traefik +
        // argos_edge + a base domain) can opt out with ARGOS_PREVIEW_ENABLED=false.
        'enabled' => (bool) env('ARGOS_PREVIEW_ENABLED', true),
        // Demos live at demo-{task}.{base_domain}. Defaults to the APP_URL host
        // (demos as subdomains of the app); locally that host has no wildcard
        // DNS, so fall back to nip.io (*.127.0.0.1.nip.io → 127.0.0.1).
        'base_domain' => env('ARGOS_PREVIEW_BASE_DOMAIN') ?: ($bareHost ? '127.0.0.1.nip.io' : $appHost),
        // Scheme + external port the demo URL is reachable on — the PUBLIC
        // endpoint, independent of the port Traefik binds on the host
        // (ARGOS_PORT). Locally they coincide (Traefik publishes 8080, demos
        // are reached on :8080). Behind an upstream TLS proxy that terminates
        // on 443 and forwards to ARGOS_PORT, set scheme=https and
        // ARGOS_PREVIEW_PORT=443 so the URL drops the port. Falls back to
        // ARGOS_PORT when ARGOS_PREVIEW_PORT is unset.
        'scheme' => env('ARGOS_PREVIEW_SCHEME') ?: $appScheme,
        'port' => (int) $envOr('ARGOS_PREVIEW_PORT', $envOr('ARGOS_PORT', 8080)),
        'ttl_hours' => (int) $envOr('ARGOS_PREVIEW_TTL_HOURS', 24),
        // Stack-wide default access protection for demos whose task is set to
        // "inherit" (the default). One of: none | session | basic.
        //   none    — public, anyone with the URL
        //   session — Argos login required (Traefik forwardAuth → the gate below)
        //   basic   — shared HTTP Basic credentials
        // Per-task overrides (tasks.demo_access_mode) win over this default.
        'auth' => $envOr('ARGOS_PREVIEW_AUTH', 'none'),
        // HTTP Basic username for basic-protected demos. The password is either
        // per-task (generated when a task is switched to basic) or this global
        // fallback for tasks that merely inherit the basic default.
        'basic_user' => $envOr('ARGOS_PREVIEW_BASIC_USER', 'demo'),
        'basic_password' => env('ARGOS_PREVIEW_BASIC_PASSWORD') ?: null,
        // Internal URL Traefik's forwardAuth middleware calls to validate the
        // Argos session for session-protected demos. Points at the in-stack
        // nginx that fronts the app; reachable from Traefik on the default net.
        'auth_gate_url' => $envOr('ARGOS_PREVIEW_AUTH_GATE_URL', 'http://nginx:80/_argos/demo-gate'),
        // External Docker network shared with Traefik (defined in docker-compose.yml).
        'network' => $envOr('ARGOS_PREVIEW_NETWORK', 'argos_edge'),
        // Shared volume where the manager writes one Traefik file-provider route
        // per demo (Traefik mounts it read-only). Matches ARGOS_TRAEFIK_DIR in
        // docker-compose.yml.
        'traefik_dir' => env('ARGOS_TRAEFIK_DIR', '/data/traefik'),
        // Cap on concurrently running demos. When a new deploy would exceed it,
        // the oldest running demos of other tasks are evicted (logged) to make
        // room. 0 disables the cap.
        'max_concurrent' => (int) $envOr('ARGOS_PREVIEW_MAX_CONCURRENT', 10),
        // Per-demo resource limits (separate from the worker limits — demos run
        // alongside Argos and should stay modest).
        'cpu_limit' => $envOr('ARGOS_PREVIEW_CPU_LIMIT', '1.0'),
        'memory_limit' => $envOr('ARGOS_PREVIEW_MEM_LIMIT', '1g'),
        // Built-in default demo runtime (php-fpm + nginx + node), used when a
        // repo ships no .argos/demo.* contract. DemoImageBuilder appends a
        // content hash to this repository name (argos-demo:<hash>) and builds
        // it on demand; the app entrypoint warms it at boot.
        'default_image' => $envOr('ARGOS_PREVIEW_DEFAULT_IMAGE', 'argos-demo'),
    ],

    /*
     * Set inside an Argos live-demo container (DemoDeployer injects ARGOS_DEMO=1
     * into the entry service). DatabaseSeeder reads this to seed the full demo
     * profile instead of the production-safe admin-only seed, so Argos' own live
     * demo is fully populated without a repo-side .argos/demo.* contract.
     */
    'demo' => [
        'enabled' => filter_var($envOr('ARGOS_DEMO', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'concept' => [
        'max_turns_default' => (int) env('ARGOS_CONCEPT_MAX_TURNS_DEFAULT', 50),
    ],
    'implement' => [
        'max_turns_default' => (int) env('ARGOS_MAX_TURNS_DEFAULT', 200),
    ],
    'factories' => [
        'github_token' => env('GITHUB_TOKEN', 'test-token'),
    ],

    /*
     * Public documentation URLs surfaced from in-app help hints. Centralising
     * them here keeps every "Learn more" link in a single place — when the
     * docs move or get a versioned URL, change it once.
     */
    'docs' => [
        'base' => 'https://github.com/nodus-it/argos/blob/master/docs',
        'setup' => 'https://github.com/nodus-it/argos/blob/master/docs/SETUP.md',
        'configuration' => 'https://github.com/nodus-it/argos/blob/master/docs/CONFIGURATION.md',
        'oauth' => 'https://github.com/nodus-it/argos/blob/master/docs/OAUTH.md',
        'setup_github' => 'https://github.com/nodus-it/argos/blob/master/docs/SETUP-GITHUB.md',
        'setup_gitlab' => 'https://github.com/nodus-it/argos/blob/master/docs/SETUP-GITLAB.md',
        'setup_bitbucket' => 'https://github.com/nodus-it/argos/blob/master/docs/SETUP-BITBUCKET.md',
        'setup_linear' => 'https://github.com/nodus-it/argos/blob/master/docs/SETUP-LINEAR.md',
        'contributing' => 'https://github.com/nodus-it/argos/blob/master/docs/CONTRIBUTING.md',
        'github_pat' => 'https://github.com/settings/tokens',
        'gitlab_pat' => 'https://gitlab.com/-/user_settings/personal_access_tokens',
        'bitbucket_pat' => 'https://support.atlassian.com/bitbucket-cloud/docs/repository-access-tokens/',
        'claude_setup_token' => 'https://docs.claude.com/en/docs/claude-code/quickstart',
    ],
];
