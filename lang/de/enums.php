<?php

declare(strict_types=1);

return [
    'workflow_status' => [
        'draft' => 'Entwurf',
        'concept_running' => 'Konzept läuft',
        'concept_review' => 'Konzept bereit',
        'implement_running' => 'Implementierung läuft',
        'implement_paused' => 'Pausiert (Turn-Limit)',
        'implement_completed' => 'Implementierung abgeschlossen',
        'in_review' => 'In Review',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
        'aborted' => 'Abgebrochen',
    ],

    'phase_status' => [
        'pending' => 'Ausstehend',
        'running' => 'Läuft',
        'completed' => 'Abgeschlossen',
        'paused' => 'Pausiert',
        'failed' => 'Fehlgeschlagen',
        'quality_gate_failed' => 'Quality Gate fehlgeschlagen',
        'no_changes' => 'Keine Änderungen',
        'lock_blocked' => 'Lock blockiert',
        'rate_limited' => 'Rate-Limit erreicht',
    ],

    'phases' => [
        'concept' => 'Concept',
        'implement' => 'Implement',
        'diff' => 'Diff',
        'push' => 'Push',
        'respond' => 'Respond',
        'commit_message' => 'Commit-Message',
    ],

    'phase_runs' => [
        'title' => 'Phase-Läufe',
        'columns' => [
            'phase' => 'Phase',
            'iteration' => '#',
            'status' => 'Status',
            'started' => 'Gestartet',
            'finished' => 'Beendet',
            'duration' => 'Dauer',
            'input' => 'Input',
            'output' => 'Output',
            'cost' => 'Kosten',
            'model' => 'Modell',
        ],
    ],

    'worker_source' => [
        'standard' => 'Standard',
        'byoi' => 'Eigenes Image (BYOI)',
        'devcontainer' => 'Devcontainer',
    ],

    'worker_image_entity_status' => [
        'active' => 'Aktiv',
        'deprecated' => 'Veraltet',
        'disabled' => 'Deaktiviert',
    ],

    'worker_image_build_status' => [
        'queued' => 'Wartet',
        'building' => 'Build läuft',
        'ready' => 'Bereit',
        'failed' => 'Fehlgeschlagen',
    ],

    'agent_credential_status' => [
        'active' => 'Aktiv',
        'expired' => 'Abgelaufen',
        'revoked' => 'Widerrufen',
    ],

    'provider_credential_status' => [
        'active' => 'Aktiv',
        'expired' => 'Abgelaufen',
        'revoked' => 'Widerrufen',
    ],

    'demo_status' => [
        'building' => 'Wird gebaut',
        'live' => 'Live',
        'failed' => 'Fehlgeschlagen',
        'stopped' => 'Gestoppt',
    ],
    'demo_access_mode' => [
        'inherit' => 'Standard',
        'session' => 'Argos-Login',
        'basic' => 'Passwortschutz',
        'public' => 'Öffentlich',
    ],
];
