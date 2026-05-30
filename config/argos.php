<?php

declare(strict_types=1);

/*
 * Single source of truth for the running app version. The release workflow
 * (.github/workflows/release.yml) rewrites the literal below with `sed` and
 * commits it together with the CHANGELOG entry, so the value here always
 * matches the git tag that ships the same commit. Local/develop builds keep
 * '0.0.0-dev' and use floating worker tags (no version pinning).
 */
$argosVersion = '0.1.0-beta.2';

return [
    'version' => env('ARGOS_VERSION', $argosVersion),
    'repo_root' => env('ARGOS_REPO_ROOT', dirname(__DIR__)),
    /*
     * AGPL-3.0 §13 source-offer URL. Every running instance must point users
     * to the corresponding source. For unmodified deployments this is the
     * upstream repo; forks must override this with their own source URL via
     * ARGOS_SOURCE_URL.
     */
    'source_url' => env('ARGOS_SOURCE_URL', 'https://github.com/nodus-it/argos'),
    'config_dir' => env('ARGOS_CONFIG_DIR', (getenv('HOME') ?: ($_SERVER['HOME'] ?? posix_getpwuid(posix_getuid())['dir'])).'/.config/argos'),
    // Empty .env entries (CLAUDE_CODE_OAUTH_TOKEN=) come back as "" from
    // env(), but readers treat null as "not configured" — normalise here so
    // hasClaudeToken() / claudeTokenSource() / Settings UI all agree.
    'claude_token' => env('CLAUDE_CODE_OAUTH_TOKEN') ?: null,
    'admin_password' => env('ADMIN_PASSWORD') ?: '12345',
    /*
     * Local one-click developer login (filament-developer-logins). Only ever
     * active in the `local` environment — the AdminPanelProvider gates the
     * plugin on app()->environment('local'), so this email is meaningless in
     * staging/production. Matches the DemoSeeder admin so the button works
     * out of the box after a dev reset.
     */
    'dev_login_email' => env('SEED_USER_EMAIL', 'admin@argos.local'),
    'docker' => [
        'memory_limit' => env('ARGOS_MEM_LIMIT', '4g'),
        'cpu_limit' => env('ARGOS_CPU_LIMIT', '2'),
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
    'concept' => [
        'max_turns_default' => (int) env('ARGOS_CONCEPT_MAX_TURNS_DEFAULT', 30),
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
