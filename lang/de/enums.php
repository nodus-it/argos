<?php

declare(strict_types=1);

return [
    'workflow_status' => [
        'draft' => 'Entwurf',
        'concept_running' => 'Konzept läuft',
        'concept_review' => 'Konzept bereit',
        'implement_running' => 'Implementierung läuft',
        'implement_paused' => 'Pausiert (Turn-Limit)',
        'in_review' => 'In Review',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
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
        ],
    ],
];
