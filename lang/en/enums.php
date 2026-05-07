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

    'phase_status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'completed' => 'Completed',
        'paused' => 'Paused',
        'failed' => 'Failed',
        'quality_gate_failed' => 'Quality Gate Failed',
        'no_changes' => 'No Changes',
        'lock_blocked' => 'Lock Blocked',
        'rate_limited' => 'Rate Limited',
    ],

    'phases' => [
        'concept' => 'Concept',
        'implement' => 'Implement',
        'diff' => 'Diff',
        'push' => 'Push',
        'respond' => 'Respond',
        'commit_message' => 'Commit Message',
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
