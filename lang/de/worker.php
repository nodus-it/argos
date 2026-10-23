<?php

declare(strict_types=1);

return [

    'credentials' => [
        'label' => 'Claude-Abo',
        'plural' => 'Claude-Abos',
        'sections' => [
            'identity' => 'Identität',
            'identity_description' => 'Für welchen Agenten dieses Konto gilt, ein Anzeigename und der Status.',
            'auth' => 'Authentifizierung',
            'auth_description' => 'Die Zugangsdaten des Agenten — je nach Agent ein Token oder die vollständige auth.json.',
        ],
        'fields' => [
            'agent_name' => 'Agent',
            'name' => 'Beschreibung',
            'name_help' => 'z.B. „Persönlich" oder „Team-Account"',
            'status' => 'Status',
            'last_validated_at' => 'Zuletzt geprüft',
            'token' => 'OAuth-Token',
            'token_help' => 'Output von `claude setup-token`',
            'owner' => 'Besitzer',
            'owner_help' => 'Auf wessen Claude-Abo dieser Agent läuft. Geteilte Agenten kann jeder in Workspaces verwenden.',
            'owner_shared' => 'Geteilt',
            'max_concurrent_sessions' => 'Parallele Sessions',
            'max_concurrent_sessions_help' => 'Wie viele Aufgaben gleichzeitig auf diesem Abo laufen dürfen.',
            'default_model' => 'Standardmodell',
            'default_model_help' => 'Vorausgewähltes Modell für neue Aufgaben.',
            'sessions' => 'Sessions',
            'usage_limited_until' => 'Nutzungslimit bis',
        ],
    ],

];
