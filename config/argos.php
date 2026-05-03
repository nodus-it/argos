<?php

declare(strict_types=1);

return [
    'repo_root' => env('ARGOS_REPO_ROOT', dirname(__DIR__)),
    'config_dir' => env('ARGOS_CONFIG_DIR', (getenv('HOME') ?: ($_SERVER['HOME'] ?? posix_getpwuid(posix_getuid())['dir'])).'/.config/argos'),
    'worker_image' => env('ARGOS_WORKER_IMAGE', 'ghcr.io/nodus-it/argos-worker:php8.4'),
    /*
     * Available worker images per environment, used to populate the dropdown
     * in RepoProfile and Task forms. Symmetric tag scheme:
     *   local: argos-worker:local-php8.3 / :local-php8.4 (built by `compose --profile build-only build`)
     *   stage: ghcr.io/.../argos-worker:stage-php8.3 / :stage-php8.4 (built by CI on develop)
     *   prod:  ghcr.io/.../argos-worker:php8.3 / :php8.4 (built by CI on tags)
     * Custom values stored on a profile/task that are not in this list are
     * preserved and shown with a "(custom)" suffix.
     */
    'worker_images' => [
        'local' => [
            'argos-worker:local-php8.4',
            'argos-worker:local-php8.3',
        ],
        'staging' => [
            'ghcr.io/nodus-it/argos-worker:stage-php8.4',
            'ghcr.io/nodus-it/argos-worker:stage-php8.3',
        ],
        'production' => [
            'ghcr.io/nodus-it/argos-worker:php8.4',
            'ghcr.io/nodus-it/argos-worker:php8.3',
        ],
    ],
    'claude_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
    'admin_password' => env('ADMIN_PASSWORD', '12345'),
    'docker' => [
        'memory_limit' => env('ARGOS_MEM_LIMIT', '4g'),
        'cpu_limit' => env('ARGOS_CPU_LIMIT', '2'),
    ],
    'implement' => [
        'max_turns_default' => (int) env('ARGOS_MAX_TURNS_DEFAULT', 200),
    ],
    'factories' => [
        'github_token' => env('GITHUB_TOKEN', 'test-token'),
    ],
];
