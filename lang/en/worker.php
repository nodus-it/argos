<?php

declare(strict_types=1);

return [

    'credentials' => [
        'label' => 'Claude subscription',
        'plural' => 'Claude subscriptions',
        'sections' => [
            'identity' => 'Identity',
            'identity_description' => 'Which agent this account is for, a display name, and the status.',
            'auth' => 'Authentication',
            'auth_description' => "The agent's credentials — a token or the full auth.json, depending on the agent.",
        ],
        'fields' => [
            'agent_name' => 'Agent',
            'name' => 'Description',
            'name_help' => 'e.g. "Personal" or "Team account"',
            'status' => 'Status',
            'last_validated_at' => 'Last validated',
            'token' => 'OAuth token',
            'token_help' => 'Output of `claude setup-token`',
            'owner' => 'Owner',
            'owner_help' => 'Whose Claude subscription this agent runs on. Shared agents can be used by everyone in workspaces.',
            'owner_shared' => 'Shared',
            'max_concurrent_sessions' => 'Parallel sessions',
            'max_concurrent_sessions_help' => 'How many workspace tasks may run on this subscription at the same time.',
            'default_model' => 'Default model',
            'default_model_help' => 'Preselected model for new workspace tasks.',
            'sessions' => 'Sessions',
            'usage_limited_until' => 'Usage limit until',
        ],
    ],

];
