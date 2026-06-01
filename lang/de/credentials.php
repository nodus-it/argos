<?php

declare(strict_types=1);

return [
    'pat' => [
        'label' => 'Access-Token',
        'plural' => 'Access-Tokens',
        'sections' => [
            'identity' => 'Identität',
            'auth' => 'Token',
        ],
        'fields' => [
            'label' => 'Bezeichnung',
            'label_help' => 'Frei wählbarer Name zur Wiedererkennung, z. B. „GitHub – Orga acme".',
            'provider' => 'Plattform',
            'instance_url' => 'Instanz-URL',
            'instance_url_help' => 'Nur für selbst gehostete Instanzen (GitLab CE/EE, Bitbucket Server). Leer lassen für die öffentliche Instanz.',
            'token' => 'Token',
            'token_help' => 'Personal Access Token mit den nötigen Scopes. Wird verschlüsselt gespeichert und nie geloggt.',
            'scopes_hint' => 'Scopes (Notiz)',
            'scopes_hint_help' => 'Optionale Notiz, mit welchen Scopes das Token erstellt wurde.',
            'status' => 'Status',
            'last_validated_at' => 'Zuletzt geprüft',
        ],
        'actions' => [
            'test' => 'Verbindung testen',
        ],
        'notifications' => [
            'test_ok' => 'Verbindung erfolgreich',
            'test_failed' => 'Verbindung fehlgeschlagen',
        ],
        'guide' => [
            'button' => 'Token bei :provider erstellen',
            'scopes' => 'Benötigte Scopes',
            'choose_provider' => 'Wähle zuerst die Plattform (und ggf. die Instanz-URL) — dann erscheint hier der direkte Link zum Token-Erstellen mit den richtigen Scopes.',
        ],
    ],

    'oauth' => [
        'label' => 'OAuth-App',
        'plural' => 'OAuth-Apps',
        'public_instance' => 'Öffentliche Instanz',
        'sections' => [
            'app' => 'OAuth-App',
            'credentials' => 'Zugangsdaten',
        ],
        'fields' => [
            'provider' => 'Plattform',
            'instance_url' => 'Instanz-URL',
            'instance_url_help' => 'Nur für selbst gehostete Instanzen. Leer lassen für die öffentliche Instanz.',
            'callback_url' => 'Callback-URL',
            'callback_url_help' => 'Diese URL exakt in der OAuth-App der Plattform als Redirect-/Callback-URL hinterlegen.',
            'callback_url_placeholder' => 'Zuerst Plattform wählen …',
            'client_id' => 'Client-ID',
            'client_secret' => 'Client-Secret',
            'client_secret_help' => 'Wird verschlüsselt gespeichert und nie geloggt.',
            'enabled' => 'Aktiv',
        ],
        'guide' => [
            'button' => 'OAuth-App bei :provider erstellen',
            'scopes' => 'Benötigte Scopes',
            'choose_provider' => 'Wähle zuerst die Plattform (und ggf. die Instanz-URL) — dann erscheint hier der direkte Link zum Anlegen der OAuth-App.',
            'callback_note' => 'Trage die Callback-URL oben in der OAuth-App als Redirect-URL ein.',
            'manual_note' => 'Lege die OAuth-App in den Workspace-Einstellungen deines Providers an (OAuth-Consumer) und trage die Callback-URL oben als Redirect-URL ein.',
        ],
    ],
];
