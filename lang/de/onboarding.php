<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Einrichtung',
    'title' => 'Argos einrichten',

    'intro' => 'In wenigen Schritten ist Argos einsatzbereit: Claude-Token hinterlegen, optional Git-Hosts verbinden und dann das erste Projekt anlegen.',

    'notifications' => [
        'env_token' => 'Token kommt aus der Umgebungsvariable',
        'empty_token' => 'Bitte einen Token eingeben',
        'invalid_token_title' => 'Token ungültig',
        'invalid_token_body' => 'Der eingegebene Token wurde von der API abgelehnt.',
        'saved_title' => 'Token gespeichert',
        'saved_unreachable_body' => 'Hinweis: Token konnte nicht gegen die API geprüft werden — Verbindung nicht erreichbar.',
        'github_disconnected' => 'GitHub-Verbindung getrennt',
        'disconnected' => ':provider getrennt',
    ],

    'steps' => [
        'claude_token' => 'Claude Token',
        'github_connect' => 'GitHub verbinden',
        'github_optional' => 'optional',
        'providers_connect' => 'Git-Host verbinden',
        'providers_optional' => 'optional',
        'first_project' => 'Erstes Projekt anlegen',
    ],

    'token' => [
        'from_env' => 'Token kommt aus der Umgebungsvariable <code class="text-xs">CLAUDE_CODE_OAUTH_TOKEN</code> — nichts zu tun.',
        'is_saved' => 'Token ist gespeichert.',
        'override_label' => 'Token überschreiben',
        'label' => 'Token',
        'placeholder' => 'sk-ant-oat01-…',
        'save_button' => 'Speichern',
        'help' => 'Wird im Config-Verzeichnis abgelegt (mode 0600).',
    ],

    'github' => [
        'connected' => 'GitHub-Account ist verbunden.',
        'disconnect' => 'Trennen',
        'tip' => 'Tipp: Wenn du beim Verbinden keine Auswahlmaske mehr siehst, widerrufe die App zuerst auf',
        'description' => 'Verbinde deinen GitHub-Account per OAuth — danach kannst du Projekte ohne Personal Access Token anlegen.',
        'connect_button' => 'Mit GitHub verbinden',
    ],

    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        'description' => 'Verbinde einen oder mehrere Git-Hosts per OAuth — danach kannst du Repos und Branches direkt aus Dropdowns auswählen statt URLs einzugeben.',
        'connect_button' => 'Mit :provider verbinden',
        'disconnect' => 'Trennen',
    ],

    'project' => [
        'description' => 'Verbinde Argos mit einem Git-Repository. Weitere Projekte kannst du danach jederzeit unter <strong>Konfiguration → Projekte</strong> anlegen.',
        'create_button' => 'Projekt anlegen',
    ],
];
