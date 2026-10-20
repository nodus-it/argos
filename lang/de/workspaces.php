<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_running' => 'Der Workspace läuft nicht. Starte ihn zuerst.',
        'daemon_unreachable' => 'Der Workspace hat nicht geantwortet: :message',
        'bypass_owner_only' => 'Nur Besitzer des Workspaces dürfen Freigaben umgehen.',
        'capacity' => 'Es laufen bereits :max Workspaces. Stoppe einen, bevor du einen weiteren startest.',
        'init_failed' => 'Das Einrichten des Repositorys ist fehlgeschlagen (:code): :message',
        'agent_unavailable' => 'Der Agent dieses Threads ist nicht verfügbar.',
        'agent_usage_limited' => 'Das Abo von :agent hat sein Nutzungslimit bis :time erreicht.',
        'agent_busy' => ':agent führt bereits :max parallele Session(s) aus. Schließe einen anderen Thread oder wähle einen anderen Agenten.',
        'collaboration_disabled' => 'Mitglieder zu Workspaces hinzuzufügen ist in dieser Installation nicht aktiviert.',
        'last_owner' => 'Ein Workspace braucht mindestens einen Besitzer.',
    ],
];
