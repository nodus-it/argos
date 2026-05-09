<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Konfiguration',
    'navigation_label' => 'Einstellungen',
    'title' => 'Einstellungen',

    'token_section_heading' => 'Claude OAuth Token',

    'token_source' => [
        'env' => 'Token ist über die Umgebungsvariable CLAUDE_CODE_OAUTH_TOKEN konfiguriert. Eingaben hier sind deaktiviert.',
        'file' => 'Token ist gespeichert. Eingabe überschreibt den vorhandenen Wert.',
        'none' => 'Kein Token konfiguriert — Phasen können nicht ausgeführt werden, bis du einen Token hinterlegst.',
    ],

    'token_field' => [
        'label' => 'Token',
        'placeholder_env' => '••• (aus Environment) •••',
        'placeholder_other' => 'sk-ant-oat01-…',
        'help_env' => 'Token kommt aus der Umgebung — im UI nicht änderbar.',
        'help_file' => 'Wird im Config-Verzeichnis abgelegt (mode 0600).',
        'help_none' => 'Wird im Config-Verzeichnis abgelegt (mode 0600). Token via "claude setup-token" erzeugen.',
    ],

    'actions' => [
        'save' => 'Token speichern',
        'remove' => 'Token entfernen',
    ],

    'notifications' => [
        'env_token_title' => 'Token kommt aus der Umgebungsvariable',
        'env_token_body' => 'Der Token kommt aus der Umgebung und kann hier nicht geändert werden.',
        'empty_token' => 'Bitte einen Token eingeben',
        'invalid_token_title' => 'Token ungültig',
        'invalid_token_body' => 'Der eingegebene Token wurde von der API abgelehnt. Bitte prüfen und erneut versuchen.',
        'saved_title' => 'Token gespeichert',
        'saved_unreachable_body' => 'Hinweis: Token konnte nicht gegen die API geprüft werden — Verbindung nicht erreichbar.',
        'removed' => 'Token entfernt',
    ],

    'blade' => [
        'badge_set' => 'gesetzt',
        'badge_not_set' => 'nicht gesetzt',
        'token_from_env' => 'Token kommt aus <code>CLAUDE_CODE_OAUTH_TOKEN</code> (ENV).',
        'token_from_file' => 'Token ist im Config-Verzeichnis hinterlegt.',
        'token_missing' => 'Phasen können nicht ausgeführt werden, bis ein Token hinterlegt ist.',

        'db_section' => 'Datenbank',
        'db_connection' => 'Aktive Verbindung: <strong>:connection</strong>',
        'db_config_hint' => 'Konfigurierbar über <code>DB_CONNECTION</code> und <code>DB_DATABASE</code>.',

        'logs_section' => 'Logs',
        'logs_description' => 'Manager-Log (PHP-Seite): Phase-Starts, Fehler, Job-Dispatches.',
        'logs_hint' => 'Worker-Logs pro Phase sind im Task-View unter „Logs" abrufbar.',
        'logs_download' => 'Application Log herunterladen',
    ],
];
