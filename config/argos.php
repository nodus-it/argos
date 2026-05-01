<?php

declare(strict_types=1);

return [
    'repo_root' => env('ARGOS_REPO_ROOT', dirname(__DIR__)),
    'config_dir' => env('ARGOS_CONFIG_DIR', (getenv('HOME') ?: ($_SERVER['HOME'] ?? posix_getpwuid(posix_getuid())['dir'])) . '/.config/argos'),
];
