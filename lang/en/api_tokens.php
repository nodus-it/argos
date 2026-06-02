<?php

declare(strict_types=1);

return [
    'title' => 'API tokens',

    'fields' => [
        'name' => 'Label',
        'abilities' => 'Abilities',
        'last_used_at' => 'Last used',
        'created_at' => 'Created',
    ],

    'actions' => [
        'create' => 'Create token',
        'revoke' => 'Revoke',
    ],

    'notifications' => [
        'created_title' => 'Token created — copy it now, it is shown only once',
    ],

    'client' => [
        'label' => 'API client',
        'plural' => 'API clients',
        'section' => 'Identity',
        'section_description' => 'A recognizable name for this programmatic access.',
        'name' => 'Name',
        'name_help' => 'A free-form name for programmatic access (e.g. "CI", "scripts"). Tokens grant full access across all projects.',
        'tokens' => 'Tokens',
    ],
];
