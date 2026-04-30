<?php

declare(strict_types=1);

return [
    'repo_root' => env('ARGOS_REPO_ROOT', dirname(__DIR__, 2)),
    'config_dir' => env('ARGOS_CONFIG_DIR', ($_SERVER['HOME'] ?? '/root') . '/.config/argos'),
];
