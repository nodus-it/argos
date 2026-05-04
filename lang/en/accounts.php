<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Configuration',
    'navigation_label' => 'Connected Accounts',
    'title' => 'Connected Accounts',

    'actions' => [
        'connect_github' => 'Connect with GitHub',
    ],

    'notifications' => [
        'github_disconnected' => 'GitHub connection disconnected',
    ],

    'blade' => [
        'github_section' => 'GitHub',
        'badge_connected' => 'Connected',
        'badge_not_connected' => 'Not connected',
        'disconnect' => 'Disconnect',
        'not_connected_description' => 'Connect your GitHub account to select repos and branches directly.',
        'connect_github' => 'Connect with GitHub',
    ],
];
