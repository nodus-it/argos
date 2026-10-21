<?php

declare(strict_types=1);

return [
    'title' => 'Task Providers',

    'form' => [
        'provider' => 'Provider',
        'mode' => 'Mode',
        'credential' => 'Access',
        'credential_help' => 'OAuth account or stored access token (PAT) for this provider.',
        'project' => 'Project / Team',
        'project_placeholder' => 'Choose provider and access first',
        'project_help' => 'Loaded automatically from the chosen access.',
        'labels_filter' => 'Labels filter',
        'groups' => [
            'oauth' => 'OAuth accounts',
            'pat' => 'Access tokens (PAT)',
        ],
    ],

    'columns' => [
        'provider' => 'Provider',
        'mode' => 'Mode',
        'status' => 'Status',
        'project' => 'Project',
        'last_poll' => 'Last poll',
        'last_error' => 'Last error',
    ],

    'actions' => [
        'setup' => 'Set up',
    ],

    'notifications' => [
        'no_credential_title' => 'No access linked',
        'no_credential_body' => 'Please select an OAuth account or access token in the binding first.',
        'setup_ok' => 'Provider set up',
        'setup_failed' => 'Setup failed',
    ],
];
