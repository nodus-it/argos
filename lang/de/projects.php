<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Konfiguration',
    'navigation_label' => 'Projekte',
    'model_label' => 'Projekt',
    'model_label_plural' => 'Projekte',

    'sections' => [
        'platform' => 'Plattform',
        'platform_description' => 'Wähle die Plattform — danach werden die weiteren Felder freigeschaltet.',
        'general' => 'Allgemein',
        'authentication' => 'Authentifizierung',
        'repository' => 'Repository',
    ],

    'fields' => [
        'platform' => 'Plattform',
        'platform_github' => 'GitHub',
        'platform_gitlab' => 'GitLab',
        'project_name' => 'Projektname',
        'auto_concept_label' => 'Konzept automatisch starten',
        'auto_concept_helper' => 'Startet die Konzept-Phase direkt nach dem Anlegen eines Tasks.',
        'auto_pr_label' => 'PR automatisch erstellen',
        'auto_pr_helper' => 'Startet die Push-Phase automatisch nach erfolgreicher Implementierung.',
        'worker_image_label' => 'Worker-Image',
        'worker_image_helper' => 'Leer lassen für globalen Standard. Andere Tags müssen in config/argos.php oder per ARGOS_WORKER_IMAGE bekannt sein.',
        'worker_image_placeholder' => 'Globaler Default (:image)',
        'auth_method_label' => 'Authentifizierungsmethode',
        'auth_method_pat' => 'Personal Access Token (PAT)',
        'auth_method_oauth' => 'OAuth (GitHub)',
        'auth_method_oauth_gitlab' => 'OAuth (GitLab)',
        'github_account_label' => 'GitHub-Account',
        'gitlab_account_label' => 'GitLab-Account',
        'repo_url_label' => 'Repo-URL',
        'token_label' => 'Token (PAT)',
        'token_helper_oauth_available' => 'GitHub-Account verfügbar — wechsle zu "Authentifizierung" für OAuth.',
        'default_branch_label' => 'Default Branch',
        'global_default' => 'Globaler Default',
    ],

    'infolist' => [
        'project_name' => 'Projektname',
        'platform' => 'Plattform',
        'authentication' => 'Authentifizierung',
        'auto_concept' => 'Konzept automatisch starten',
        'auto_pr' => 'PR automatisch erstellen',
        'worker_image' => 'Worker-Image',
        'worker_image_placeholder' => 'Globaler Default',
        'repo_url' => 'Repo-URL',
        'default_branch' => 'Default Branch',
        'token' => 'Token (PAT)',
    ],

    'columns' => [
        'branch' => 'Branch',
        'tasks' => 'Tasks',
    ],
];
