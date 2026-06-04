<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Configuration',
    'navigation_label' => 'Connected Accounts',
    'title' => 'Connected Accounts',

    'actions' => [
        'connect_github' => 'Connect with GitHub',
        'connect_gitlab' => 'Connect with GitLab',
        'connect_gitlab_instance' => 'Connect with GitLab (:instance)',
        'connect_bitbucket' => 'Connect with Bitbucket',
        'connect_linear' => 'Connect with Linear',
    ],

    'notifications' => [
        'github_disconnected' => 'GitHub connection disconnected',
        'gitlab_disconnected' => 'GitLab connection disconnected',
        'bitbucket_disconnected' => 'Bitbucket connection disconnected',
        'linear_disconnected' => 'Linear connection disconnected',
    ],

    'blade' => [
        'avatar_alt' => 'Avatar',
        'github_section' => 'GitHub',
        'gitlab_section' => 'GitLab',
        'bitbucket_section' => 'Bitbucket',
        'badge_connected' => 'Connected',
        'badge_not_connected' => 'Not connected',
        'badge_not_configured' => 'Not configured',
        'disconnect' => 'Disconnect',
        'not_connected_description' => 'Connect your GitHub account to select repos and branches directly.',
        'connect_github' => 'Connect with GitHub',
        'gitlab_not_connected_description' => 'Connect your GitLab account to select repos and branches directly.',
        'connect_gitlab' => 'Connect with GitLab',
        'gitlab_not_configured_description' => 'Add a GitLab OAuth app under Configuration → OAuth Apps to enable GitLab integration.',
        'bitbucket_not_connected_description' => 'Connect your Bitbucket account to select repos and branches directly.',
        'connect_bitbucket' => 'Connect with Bitbucket',
        'bitbucket_not_configured_description' => 'Add a Bitbucket OAuth app under Configuration → OAuth Apps to enable Bitbucket integration.',
        'linear_section' => 'Linear',
        'linear_not_connected_description' => 'Connect your Linear account to import issues and manage webhooks directly.',
        'connect_linear' => 'Connect with Linear',
        'linear_not_configured_description' => 'Add a Linear OAuth app under Configuration → OAuth Apps to enable Linear integration.',
        'setup_link' => 'Setup guide',
        'github_not_configured_description' => 'Add a GitHub OAuth app under Configuration → OAuth Apps to enable GitHub integration.',
    ],
];
