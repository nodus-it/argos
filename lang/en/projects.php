<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Configuration',
    'navigation_label' => 'Projects',
    'model_label' => 'Project',
    'model_label_plural' => 'Projects',

    'sections' => [
        'platform' => 'Platform',
        'platform_description' => 'Choose a platform — the remaining fields will be unlocked afterwards.',
        'general' => 'General',
        'authentication' => 'Authentication',
        'repository' => 'Repository',
    ],

    'fields' => [
        'platform' => 'Platform',
        'platform_github' => 'GitHub',
        'platform_gitlab' => 'GitLab',
        'project_name' => 'Project Name',
        'auto_concept_label' => 'Auto-start concept',
        'auto_concept_helper' => 'Starts the concept phase directly after a task is created.',
        'auto_pr_label' => 'Auto-create PR',
        'auto_pr_helper' => 'Starts the push phase automatically after a successful implementation.',
        'worker_image_label' => 'Worker Image',
        'worker_image_helper' => 'Leave empty for global default. Other tags must be listed in config/argos.php or via ARGOS_WORKER_IMAGE.',
        'worker_image_placeholder' => 'Global default (:image)',
        'auth_method_label' => 'Authentication method',
        'auth_method_pat' => 'Personal Access Token (PAT)',
        'auth_method_oauth' => 'OAuth (GitHub)',
        'auth_method_oauth_gitlab' => 'OAuth (GitLab)',
        'github_account_label' => 'GitHub Account',
        'gitlab_account_label' => 'GitLab Account',
        'repo_url_label' => 'Repo URL',
        'token_label' => 'Token (PAT)',
        'token_helper_oauth_available' => 'GitHub account available — switch to "Authentication" for OAuth.',
        'default_branch_label' => 'Default Branch',
        'global_default' => 'Global default',
    ],

    'infolist' => [
        'project_name' => 'Project Name',
        'platform' => 'Platform',
        'authentication' => 'Authentication',
        'auto_concept' => 'Auto-start concept',
        'auto_pr' => 'Auto-create PR',
        'worker_image' => 'Worker Image',
        'worker_image_placeholder' => 'Global default',
        'repo_url' => 'Repo URL',
        'default_branch' => 'Default Branch',
        'token' => 'Token (PAT)',
    ],

    'columns' => [
        'branch' => 'Branch',
        'tasks' => 'Tasks',
    ],
];
