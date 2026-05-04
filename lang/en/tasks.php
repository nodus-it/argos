<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Tasks',
    'navigation_label' => 'Tasks',

    'fields' => [
        'project' => 'Project',
        'auto_concept_label' => 'Start concept immediately',
        'auto_concept_helper' => 'Starts the concept phase immediately after creation.',
        'max_turns_label' => 'Max turns for implement',
        'max_turns_helper' => 'Upper limit for tool calls per implement run. Empty = default :default.',
        'base_branch_label' => 'Base Branch (Override)',
        'base_branch_helper' => 'Overrides the base branch for this specific task. Empty = project default.',
        'worker_image_label' => 'Worker Image (Override)',
        'worker_image_helper' => 'Overrides the image for this specific task. Empty = project default.',
    ],

    'columns' => [
        'project' => 'Project',
        'phase' => 'Phase',
        'status' => 'Status',
        'workflow' => 'Workflow',
        'cost' => 'Cost',
        'tokens' => 'Tokens',
        'created' => 'Created',
    ],
];
