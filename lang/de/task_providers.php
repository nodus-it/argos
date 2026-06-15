<?php

declare(strict_types=1);

return [
    'title' => 'Task-Provider',

    'form' => [
        'provider' => 'Provider',
        'mode' => 'Modus',
        'credential' => 'Zugang',
        'credential_help' => 'OAuth-Account oder gespeichertes Access-Token (PAT) für diesen Provider.',
        'project' => 'Projekt / Team',
        'project_placeholder' => 'Erst Provider und Zugang wählen',
        'project_help' => 'Wird automatisch aus dem gewählten Zugang geladen.',
        'labels_filter' => 'Labels-Filter',
        'close_on_complete' => 'Issue schließen bei Task-Abschluss',
        'close_on_complete_help' => 'Schließt/resolved das Quell-Issue, sobald der Argos-Task als erledigt markiert wird.',
        'groups' => [
            'oauth' => 'OAuth-Accounts',
            'pat' => 'Access-Tokens (PAT)',
        ],
    ],

    'columns' => [
        'provider' => 'Provider',
        'mode' => 'Modus',
        'status' => 'Status',
        'project' => 'Projekt',
        'last_poll' => 'Letzter Poll',
        'last_error' => 'Letzter Fehler',
    ],

    'actions' => [
        'setup' => 'Einrichten',
    ],

    'notifications' => [
        'no_credential_title' => 'Kein Zugang verknüpft',
        'no_credential_body' => 'Bitte zuerst einen OAuth-Account oder ein Access-Token im Binding auswählen.',
        'setup_ok' => 'Provider eingerichtet',
        'setup_failed' => 'Einrichtung fehlgeschlagen',
    ],
];
