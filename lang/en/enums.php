<?php

declare(strict_types=1);

return [
    'workflow_status' => [
        'draft' => 'Draft',
        'concept_running' => 'Concept running',
        'concept_review' => 'Concept ready',
        'implement_running' => 'Implementation running',
        'implement_paused' => 'Paused (turn limit)',
        'in_review' => 'In Review',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    'phases' => [
        'concept' => 'Concept',
        'implement' => 'Implement',
        'diff' => 'Diff',
        'push' => 'Push',
        'respond' => 'Respond',
    ],

    'phase_runs' => [
        'title' => 'Phase Runs',
        'columns' => [
            'phase' => 'Phase',
            'iteration' => '#',
            'status' => 'Status',
            'started' => 'Started',
            'finished' => 'Finished',
            'duration' => 'Duration',
            'input' => 'Input',
            'output' => 'Output',
            'cost' => 'Cost',
        ],
    ],
];
