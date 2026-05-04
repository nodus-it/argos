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

    'phases' => [
        'concept' => 'Concept',
        'implement' => 'Implement',
        'diff' => 'Diff',
        'push' => 'Push',
        'respond' => 'Respond',
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
