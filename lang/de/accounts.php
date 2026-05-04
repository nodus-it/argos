<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Konfiguration',
    'navigation_label' => 'Verknüpfte Accounts',
    'title' => 'Verknüpfte Accounts',

    'actions' => [
        'connect_github' => 'Mit GitHub verbinden',
        'connect_gitlab' => 'Mit GitLab verbinden',
    ],

    'notifications' => [
        'github_disconnected' => 'GitHub-Verbindung getrennt',
        'gitlab_disconnected' => 'GitLab-Verbindung getrennt',
    ],

    'blade' => [
        'github_section' => 'GitHub',
        'gitlab_section' => 'GitLab',
        'badge_connected' => 'Verbunden',
        'badge_not_connected' => 'Nicht verbunden',
        'disconnect' => 'Trennen',
        'not_connected_description' => 'Verbinde deinen GitHub-Account, um Repos und Branches direkt auszuwählen.',
        'connect_github' => 'Mit GitHub verbinden',
        'gitlab_not_connected_description' => 'Verbinde deinen GitLab-Account, um Repos und Branches direkt auszuwählen.',
        'connect_gitlab' => 'Mit GitLab verbinden',
    ],
];
