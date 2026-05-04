<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Aufgaben',
    'navigation_label' => 'Tasks',

    'fields' => [
        'project' => 'Projekt',
        'auto_concept_label' => 'Konzept direkt starten',
        'auto_concept_helper' => 'Startet die Konzept-Phase sofort nach dem Anlegen.',
        'max_turns_label' => 'Max-Turns für Implement',
        'max_turns_helper' => 'Obergrenze für Tool-Calls pro Implement-Lauf. Leer = Default :default.',
        'base_branch_label' => 'Base Branch (Override)',
        'base_branch_helper' => 'Überschreibt den Start-Branch für genau diesen Task. Leer = Projekt-Default.',
        'worker_image_label' => 'Worker-Image (Override)',
        'worker_image_helper' => 'Überschreibt das Image für genau diesen Task. Leer = Projekt-Default.',
    ],

    'columns' => [
        'project' => 'Projekt',
        'phase' => 'Phase',
        'status' => 'Status',
        'workflow' => 'Workflow',
        'cost' => 'Kosten',
        'tokens' => 'Tokens',
        'created' => 'Erstellt',
    ],
];
