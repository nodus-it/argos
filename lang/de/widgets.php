<?php

declare(strict_types=1);

return [
    'stats' => [
        'running_workers' => 'Laufende Worker',
        'workers_active' => 'Container arbeiten gerade',
        'workers_idle' => 'Keine aktiven Worker',
        'in_progress' => 'In Bearbeitung',
        'tasks_running_one' => '1 Task läuft',
        'tasks_running_many' => ':count Tasks laufen',
        'waiting' => 'Wartet auf dich',
        'review_open' => 'Review oder Antwort offen',
        'nothing_todo' => 'Nichts zu tun',
    ],

    'current_tasks' => [
        'heading' => 'Aktuelle Tasks',
        'columns' => [
            'task' => 'Task',
            'project' => 'Projekt',
            'phase' => 'Phase',
            'workflow' => 'Workflow',
            'last_updated' => 'Zuletzt',
        ],
        'empty_heading' => 'Noch keine Tasks',
        'empty_description' => 'Lege unter Aufgaben → Tasks deinen ersten Task an.',
    ],
];
