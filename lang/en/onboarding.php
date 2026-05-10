<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Setup',
    'title' => 'Set up Argos',

    'intro' => 'Argos is ready in a few steps: authenticate at least one agent, optionally connect Git hosts, then create your first project.',

    'notifications' => [
        'env_token' => 'Token comes from the environment variable',
        'empty_token' => 'Please enter a token',
        'invalid_token_title' => 'Token invalid',
        'invalid_token_body' => 'The entered token was rejected by the API.',
        'saved_title' => 'Token saved',
        'saved_unreachable_body' => 'Note: Token could not be verified against the API — connection not reachable.',
        'empty_codex' => 'Please paste the auth.json content',
        'invalid_codex_title' => 'auth.json invalid',
        'invalid_codex_body' => 'The pasted content is not valid JSON.',
        'codex_saved' => 'Codex credential saved',
        'github_disconnected' => 'GitHub connection disconnected',
        'disconnected' => ':provider disconnected',
    ],

    'steps' => [
        'claude_token' => 'Claude Token',
        'agents' => 'Authenticate agent',
        'agents_hint' => 'at least one',
        'github_connect' => 'Connect GitHub',
        'github_optional' => 'optional',
        'providers_connect' => 'Connect Git host',
        'providers_optional' => 'optional',
        'first_project' => 'Create first project',
    ],

    'agents' => [
        'claude_label' => 'Claude Code',
        'claude_hint' => 'Generate a token with <code class="text-xs">claude setup-token</code> and paste it below.',
        'codex_label' => 'OpenAI Codex',
        'codex_hint' => 'Run <code class="text-xs">codex login</code>, then paste the contents of <code class="text-xs">~/.codex/auth.json</code> below.',
        'codex_placeholder' => '{"OPENAI_API_KEY": null, "tokens": {…}, …}',
        'codex_saved_short' => 'Saved — paste new content to overwrite.',
    ],

    'token' => [
        'from_env' => 'Token comes from the environment variable <code class="text-xs">CLAUDE_CODE_OAUTH_TOKEN</code> — nothing to do.',
        'is_saved_short' => 'Saved — paste a new token to overwrite.',
        'placeholder' => 'sk-ant-oat01-…',
        'save_button' => 'Save',
    ],

    'github' => [
        'connected' => 'GitHub account is connected.',
        'disconnect' => 'Disconnect',
        'tip' => 'Tip: If you no longer see a selection screen when connecting, revoke the app first at',
        'description' => 'Connect your GitHub account via OAuth — then you can create projects without a Personal Access Token.',
        'connect_button' => 'Connect with GitHub',
    ],

    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        'description' => 'Connect one or more Git hosts via OAuth — afterwards you can pick repos and branches from dropdowns instead of typing URLs.',
        'connect_button' => 'Connect with :provider',
        'disconnect' => 'Disconnect',
    ],

    'project' => [
        'description' => 'Connect Argos to a Git repository. You can add more projects at any time under <strong>Configuration → Projects</strong>.',
        'create_button' => 'Create project',
    ],
];
