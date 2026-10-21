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
        'close_on_merge' => 'Ticket beim Merge schließen',
        'close_on_merge_help' => 'GitHub- und GitLab-Issues: Der Pull Request enthält „Closes #…“, der Merge schließt das Ticket. Aus: „Refs #…“ – das Ticket bleibt offen.',
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
        'close_on_merge' => 'Schließt beim Merge',
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
    'custom' => [
        'label' => 'Eigenes System',
        'name' => 'Name des Systems',
        'name_help' => 'So heißt das System in Argos, z. B. „Support-Desk“.',
        'callback_url' => 'Webhook-URL',
        'callback_url_help' => 'Argos meldet hierhin, was mit den Aufgaben passiert (gestartet, Plan wartet, Pull Request, fertig, abgebrochen), signiert mit dem Geheimnis unten (X-Argos-Signature: sha256=…). Aufgaben kommen über die REST-API herein (POST /api/v1/workspaces/{workspace}/tasks mit „ticket“).',
        'secret' => 'Signatur-Geheimnis',
        'secret_help' => 'Prüfe den Header X-Argos-Signature gegen den HMAC-SHA256 des Request-Bodys mit diesem Geheimnis.',
    ],
];
