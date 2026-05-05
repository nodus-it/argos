<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Konfiguration',
    'navigation_label' => 'Verknüpfte Accounts',
    'title' => 'Verknüpfte Accounts',

    'actions' => [
        'connect_github' => 'Mit GitHub verbinden',
        'connect_gitlab' => 'Mit GitLab verbinden',
        'connect_bitbucket' => 'Mit Bitbucket verbinden',
    ],

    'notifications' => [
        'github_disconnected' => 'GitHub-Verbindung getrennt',
        'gitlab_disconnected' => 'GitLab-Verbindung getrennt',
        'bitbucket_disconnected' => 'Bitbucket-Verbindung getrennt',
    ],

    'blade' => [
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
        'gitlab_not_configured_description' => 'Setze GITLAB_CLIENT_ID und GITLAB_CLIENT_SECRET in deiner .env, um die GitLab-Integration zu aktivieren.',
        'bitbucket_not_connected_description' => 'Verbinde deinen Bitbucket-Account, um Repos und Branches direkt auszuwählen.',
        'connect_bitbucket' => 'Mit Bitbucket verbinden',
        'bitbucket_not_configured_description' => 'Setze BITBUCKET_CLIENT_ID und BITBUCKET_CLIENT_SECRET in deiner .env, um die Bitbucket-Integration zu aktivieren.',
        'setup_link' => 'Setup-Anleitung',
        'github_not_configured_description' => 'Setze GITHUB_CLIENT_ID und GITHUB_CLIENT_SECRET in deiner .env, um die GitHub-OAuth-Integration zu aktivieren.',
    ],
];
