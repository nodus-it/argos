<?php

declare(strict_types=1);

return [
    'pat' => [
        'label' => 'Access-Token',
        'plural' => 'Access-Tokens',
        'sections' => [
            'identity' => 'Identität',
            'identity_description' => 'Plattform, optionale Instanz-URL und eine Bezeichnung zur Wiedererkennung.',
            'auth' => 'Token',
            'auth_description' => 'Das Personal Access Token mit den nötigen Scopes — verschlüsselt gespeichert, nie geloggt.',
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
            'app_description' => 'Plattform, optionale Instanz-URL und die Callback-URL für den OAuth-Flow.',
            'credentials' => 'Zugangsdaten',
            'credentials_description' => 'Client-ID und Client-Secret der OAuth-App — verschlüsselt gespeichert, nie geloggt.',
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
        'refresh' => [
            'expired_no_refresh_token' => 'Das OAuth-Token für :provider ist abgelaufen und es liegt kein refresh_token vor — bitte Account neu verbinden.',
            'failed' => 'OAuth-Token-Refresh für :provider fehlgeschlagen (HTTP :status) — bitte Account neu verbinden.',
            'no_access_token' => 'OAuth-Token-Refresh für :provider lieferte kein access_token — bitte Account neu verbinden.',
            'unknown_provider' => 'Unbekannter OAuth-Provider für Token-Refresh: :provider',
        ],
    ],

    'verify' => [
        'rejected_title' => 'Zugangsdaten abgelehnt',
        'unreachable_title' => 'Zugangsdaten konnten nicht geprüft werden',
        'oauth_scope_title' => 'Konto verbunden, aber die API hat es abgelehnt',
        'token_rejected' => 'Der Provider hat dieses Token abgelehnt. Prüfe, ob es korrekt ist und die nötigen Scopes hat.',
        'provider_unreachable' => 'Der Provider war nicht erreichbar — die Zugangsdaten wurden gespeichert, aber nicht verifiziert.',
    ],
];
