<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Configuration',
    'navigation_label' => 'Connected Accounts',
    'title' => 'Connected Accounts',

    'actions' => [
        'connect_github' => 'Connect with GitHub',
        'connect_gitlab' => 'Connect with GitLab',
    ],

    'notifications' => [
        'github_disconnected' => 'GitHub connection disconnected',
        'gitlab_disconnected' => 'GitLab connection disconnected',
    ],

    'blade' => [
        'github_section' => 'GitHub',
        'gitlab_section' => 'GitLab',
        'badge_connected' => 'Connected',
        'badge_not_connected' => 'Not connected',
        'disconnect' => 'Disconnect',
        'not_connected_description' => 'Connect your GitHub account to select repos and branches directly.',
        'connect_github' => 'Connect with GitHub',
        'gitlab_not_connected_description' => 'Connect your GitLab account to select repos and branches directly.',
        'connect_gitlab' => 'Connect with GitLab',
    ],
];
