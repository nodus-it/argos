<?php

declare(strict_types=1);

return [
    'stats' => [
        'running_workers' => 'Running Workers',
        'workers_active' => 'Containers working',
        'workers_idle' => 'No active workers',
        'in_progress' => 'In Progress',
        'tasks_running_one' => '1 task running',
        'tasks_running_many' => ':count tasks running',
        'waiting' => 'Waiting for you',
        'review_open' => 'Review or response pending',
        'nothing_todo' => 'Nothing to do',
        'worker_updates' => 'Worker Updates',
        'worker_updates_pending' => 'Image rebuilds available',
        'worker_updates_clean' => 'All up to date',
    ],

    'current_tasks' => [
        'heading' => 'Current Tasks',
        'columns' => [
            'task' => 'Task',
            'project' => 'Project',
            'phase' => 'Phase',
            'workflow' => 'Workflow',
            'last_updated' => 'Last updated',
        ],
        'empty_heading' => 'No tasks yet',
        'empty_description' => 'Create your first task under Tasks.',
    ],
];
