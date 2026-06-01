<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Setup',
    'title' => 'Set up Argos',

    'nav' => [
        'next' => 'Continue',
        'back' => 'Back',
    ],

    'steps' => [
        'agents' => 'Agents',
        'repository' => 'Repository',
        'done' => 'Done',
    ],

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
        'disconnected' => ':provider disconnected',
        'need_agent' => 'Authenticate at least one agent first',
        'repo_incomplete' => 'Please pick a repository and branch',
        'name_taken_title' => 'Name already in use',
        'name_taken_body' => 'A project with this name already exists — choose a different one.',
        'project_created' => 'Project created',
    ],

    'agents' => [
        'heading' => 'Which agent do you want to use?',
        'description' => 'Authenticate at least one coding agent. You can add or change agents later.',
        'gate_hint' => 'At least one agent required',
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

    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        'connect_button' => 'Connect with :provider',
        'disconnect' => 'Disconnect',
    ],

    'repo' => [
        'heading' => 'Connect a repository',
        'description' => 'Authorize a Git host, then pick the repository Argos should work on.',
        'authorize_heading' => '1 · Authorize access',
        'oauth_card_title' => 'OAuth',
        'oauth_card_desc' => 'Connect in one click — then pick repos and branches from dropdowns.',
        'oauth_none' => 'No OAuth app configured yet.',
        'pat_card_title' => 'Access token (PAT)',
        'pat_card_desc' => 'Store a token directly — also works for self-hosted GitLab and GitHub Enterprise.',
        'pat_link' => 'Add a token',
        'oauth_app_link' => 'Set up an OAuth app',
        'oauth_add_more' => 'Add another OAuth app',
        'pick_heading' => '2 · Pick a repository',
        'source_label' => 'Source',
        'source_placeholder' => 'Choose a connected account or token…',
        'group_oauth' => 'Connected accounts',
        'group_pat' => 'Access tokens (PAT)',
        'repo_label' => 'Repository',
        'repo_placeholder' => 'Choose a repository…',
        'no_repos' => 'No repositories found for this source.',
        'branch_label' => 'Default branch',
        'branch_placeholder' => 'Choose a branch…',
        'name_label' => 'Project name',
        'create_button' => 'Create project',
    ],

    'done' => [
        'heading' => 'Argos is ready',
        'description' => 'Your first project is connected. Create a task to let an agent turn a plan into a pull request.',
        'create_task' => 'Create first task',
        'view_project' => 'View project',
    ],
];
