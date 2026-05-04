<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Configuration',
    'navigation_label' => 'Settings',
    'title' => 'Settings',

    'token_section_heading' => 'Claude OAuth Token',

    'token_source' => [
        'env' => 'Token is configured via the CLAUDE_CODE_OAUTH_TOKEN environment variable. Inputs here are disabled.',
        'file' => 'Token is saved. Input overwrites the existing value.',
        'none' => 'No token configured — phases cannot run until you provide a token.',
    ],

    'token_field' => [
        'label' => 'Token',
        'placeholder_env' => '••• (from environment) •••',
        'placeholder_other' => 'sk-ant-oat01-…',
        'help_env' => 'Token comes from the environment — cannot be changed in the UI.',
        'help_file' => 'Stored in the config directory (mode 0600).',
        'help_none' => 'Stored in the config directory (mode 0600). Generate a token via "claude setup-token".',
    ],

    'actions' => [
        'save' => 'Save token',
        'remove' => 'Remove token',
    ],

    'notifications' => [
        'env_token_title' => 'Token comes from the environment variable',
        'env_token_body' => 'The token comes from the environment and cannot be changed here.',
        'empty_token' => 'Please enter a token',
        'invalid_token_title' => 'Token invalid',
        'invalid_token_body' => 'The entered token was rejected by the API. Please check and try again.',
        'saved_title' => 'Token saved',
        'saved_unreachable_body' => 'Note: Token could not be verified against the API — connection not reachable.',
        'removed' => 'Token removed',
    ],

    'blade' => [
        'badge_set' => 'set',
        'badge_not_set' => 'not set',
        'token_from_env' => 'Token comes from <code>CLAUDE_CODE_OAUTH_TOKEN</code> (ENV).',
        'token_from_file' => 'Token is stored in the config directory.',
        'token_missing' => 'Phases cannot run until a token is provided.',

        'db_section' => 'Database',
        'db_connection' => 'Active connection: <strong>:connection</strong>',
        'db_config_hint' => 'Configurable via <code>DB_CONNECTION</code> and <code>DB_DATABASE</code>.',

        'worker_section' => 'Worker Image',
        'worker_config_hint' => 'Configurable via <code>ARGOS_WORKER_IMAGE</code>.',

        'logs_section' => 'Logs',
        'logs_description' => 'Manager log (PHP side): phase starts, errors, job dispatches.',
        'logs_hint' => 'Worker logs per phase are accessible in the task view under "Logs".',
        'logs_download' => 'Download Application Log',
    ],
];
