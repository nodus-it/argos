<?php

declare(strict_types=1);

return [
    'learn_more' => 'Learn more',
    'docs' => 'Docs',

    'platform' => [
        'github' => [
            'title' => 'GitHub Setup',
            'body' => 'You only need a Personal Access Token with the `repo` scope. Token expired? Generate a new one and update the project.',
            'link_label' => 'Create a token',
            'link_url' => ':github_pat',
            'doc_label' => 'Full guide',
            'doc_url' => ':setup_github',
        ],
        'gitlab' => [
            'title' => 'GitLab Setup',
            'body' => 'Works with self-hosted GitLab too — set `GITLAB_INSTANCE_URL` in your env. Token needs `api` and `write_repository`.',
            'link_label' => 'Create a token',
            'link_url' => ':gitlab_pat',
            'doc_label' => 'Full guide',
            'doc_url' => ':setup_gitlab',
        ],
        'bitbucket' => [
            'title' => 'Bitbucket Setup',
            'body' => 'Bitbucket uses Repository Access Tokens (scoped per repo). Paste the token directly — no username prefix needed.',
            'link_label' => 'Create a Repository Access Token',
            'link_url' => ':bitbucket_pat',
            'doc_label' => 'Full guide',
            'doc_url' => ':setup_bitbucket',
        ],
    ],

    'oauth' => [
        'available' => [
            'title' => 'OAuth available',
            'body' => 'You already have a connected account — switch the "Authentication" field to OAuth to pick repos and branches from dropdowns instead of typing URLs.',
        ],
        'connected' => [
            'title' => 'Account connected',
            'body' => 'Repos and branches are loaded automatically from your connected account.',
        ],
        'not_configured' => [
            'title' => 'OAuth not configured',
            'body' => 'You can still create projects with a Personal Access Token. OAuth enables repo/branch dropdowns and multi-user setups.',
            'doc_label' => 'When is OAuth worth it?',
            'doc_url' => ':oauth',
        ],
        'overview' => [
            'title' => 'What does OAuth get me?',
            'body' => 'Connected accounts replace per-project Personal Access Tokens. Pick repos and branches from dropdowns instead of typing URLs, and let each user connect their own accounts.',
            'doc_label' => 'OAuth overview',
            'doc_url' => ':oauth',
        ],
    ],

    'task' => [
        'description' => [
            'title' => 'Tips for good task descriptions',
            'body' => 'Concrete acceptance criteria lead to better PRs. Describe what should happen, *why*, and how you will know it worked. Skip the user-story formatting — the agent is not a PM.',
        ],
        'no_projects' => [
            'title' => 'No project yet',
            'body' => 'You need a project with a repo and a token before you can create a task.',
        ],
    ],

    'claude_token' => [
        'expiry' => [
            'title' => 'Token expiry',
            'body' => 'Claude tokens expire after a few weeks. If tasks suddenly fail with "401 Unauthorized": run <code>claude setup-token</code> again and paste the new token.',
            'doc_label' => 'Claude Code quickstart',
            'doc_url' => ':claude_setup_token',
        ],
    ],

    'configuration' => [
        'env_reference' => [
            'title' => 'Which variables can I set?',
            'body' => 'The complete list of environment variables with defaults lives in the docs.',
            'doc_label' => 'Configuration Reference',
            'doc_url' => ':configuration',
        ],
    ],
];
