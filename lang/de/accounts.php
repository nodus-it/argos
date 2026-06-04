<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Konfiguration',
    'navigation_label' => 'Verknüpfte Accounts',
    'title' => 'Verknüpfte Accounts',

    'actions' => [
        'connect_github' => 'Mit GitHub verbinden',
        'connect_gitlab' => 'Mit GitLab verbinden',
        'connect_gitlab_instance' => 'Mit GitLab verbinden (:instance)',
        'connect_bitbucket' => 'Mit Bitbucket verbinden',
        'connect_linear' => 'Mit Linear verbinden',
    ],

    'notifications' => [
        'github_disconnected' => 'GitHub-Verbindung getrennt',
        'gitlab_disconnected' => 'GitLab-Verbindung getrennt',
        'bitbucket_disconnected' => 'Bitbucket-Verbindung getrennt',
        'linear_disconnected' => 'Linear-Verbindung getrennt',
    ],

    'blade' => [
        'avatar_alt' => 'Avatar',
        'github_section' => 'GitHub',
        'gitlab_section' => 'GitLab',
        'bitbucket_section' => 'Bitbucket',
        'badge_connected' => 'Verbunden',
        'badge_not_connected' => 'Nicht verbunden',
        'badge_not_configured' => 'Nicht konfiguriert',
        'disconnect' => 'Trennen',
        'not_connected_description' => 'Verbinde deinen GitHub-Account, um Repos und Branches direkt auszuwählen.',
        'connect_github' => 'Mit GitHub verbinden',
        'gitlab_not_connected_description' => 'Verbinde deinen GitLab-Account, um Repos und Branches direkt auszuwählen.',
        'connect_gitlab' => 'Mit GitLab verbinden',
        'gitlab_not_configured_description' => 'Lege unter Konfiguration → OAuth-Apps eine GitLab-OAuth-App an, um die GitLab-Integration zu aktivieren.',
        'bitbucket_not_connected_description' => 'Verbinde deinen Bitbucket-Account, um Repos und Branches direkt auszuwählen.',
        'connect_bitbucket' => 'Mit Bitbucket verbinden',
        'bitbucket_not_configured_description' => 'Lege unter Konfiguration → OAuth-Apps eine Bitbucket-OAuth-App an, um die Bitbucket-Integration zu aktivieren.',
        'linear_section' => 'Linear',
        'linear_not_connected_description' => 'Verbinde deinen Linear-Account, um Issues zu importieren und Webhooks direkt zu verwalten.',
        'connect_linear' => 'Mit Linear verbinden',
        'linear_not_configured_description' => 'Lege unter Konfiguration → OAuth-Apps eine Linear-OAuth-App an, um die Linear-Integration zu aktivieren.',
        'setup_link' => 'Setup-Anleitung',
        'github_not_configured_description' => 'Lege unter Konfiguration → OAuth-Apps eine GitHub-OAuth-App an, um die GitHub-Integration zu aktivieren.',
    ],
];
