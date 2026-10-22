<?php

declare(strict_types=1);

return [
    'model_label' => 'issue tracker',
    'model_label_plural' => 'issue trackers',
    'title' => 'Task Providers',

    'form' => [
        'provider' => 'Provider',
        'mode' => 'Mode',
        'credential_not_yours' => 'That connected account is not yours — pick one of your own.',
        'credential' => 'Access',
        'credential_help' => 'OAuth account or stored access token (PAT) for this provider.',
        'project' => 'Project / Team',
        'project_placeholder' => 'Choose provider and access first',
        'project_help' => 'Loaded automatically from the chosen access.',
        'labels_filter' => 'Labels filter',
        'close_on_merge' => 'Close the ticket on merge',
        'close_on_merge_help' => 'GitHub and GitLab issues: the pull request says "Closes #…", so merging it closes the ticket. Off: "Refs #…" — the ticket stays open.',
        'groups' => [
            'oauth' => 'OAuth accounts',
            'pat' => 'Access tokens (PAT)',
        ],
    ],

    'columns' => [
        'provider' => 'Provider',
        'mode' => 'Mode',
        'status' => 'Status',
        'project' => 'Project',
        'last_poll' => 'Last poll',
        'last_error' => 'Last error',
        'close_on_merge' => 'Closes on merge',
    ],

    'actions' => [
        'setup' => 'Set up',
    ],

    'notifications' => [
        'no_credential_title' => 'No access linked',
        'no_credential_body' => 'Please select an OAuth account or access token in the binding first.',
        'setup_ok' => 'Provider set up',
        'setup_failed' => 'Setup failed',
    ],
    'custom' => [
        'label' => 'Own system',
        'name' => 'System name',
        'name_help' => 'How the system is called in Argos, e.g. "Support desk".',
        'callback_url' => 'Webhook URL',
        'callback_url_help' => 'Argos posts what happens to the tasks here (started, plan waiting, pull request, done, cancelled), signed with the secret below (X-Argos-Signature: sha256=…). Tasks come in through the REST API (POST /api/v1/workspaces/{workspace}/tasks with a "ticket").',
        'secret' => 'Signing secret',
        'secret_help' => 'Check the X-Argos-Signature header against the HMAC-SHA256 of the request body with this secret.',
    ],
];
