<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Setup',
    'title' => 'Set up Argos',

    'nav' => [
        'next' => 'Continue',
        'back' => 'Back',
    ],

    'language' => 'Language',

    'steps' => [
        'security' => 'Account',
        'agents' => 'Claude subscription',
        'repository' => 'Workspace',
        'done' => 'Done',
    ],

    'notifications' => [
        'password_invalid_title' => 'Password not changed',
        'password_saved' => 'Password updated',
        'empty_token' => 'Please enter a token',
        'invalid_token_title' => 'Token invalid',
        'invalid_token_body' => 'The entered token was rejected by the API.',
        'avatar_saved' => 'Profile picture saved',
        'saved_title' => 'Token saved',
        'saved_unreachable_body' => 'Note: Token could not be verified against the API — connection not reachable.',
        'disconnected' => ':provider disconnected',
        'need_agent' => 'Add your Claude subscription first',
        'repo_incomplete' => 'Please pick a repository and branch',
        'name_taken_title' => 'Name already in use',
        'name_taken_body' => 'A workspace with this name already exists — choose a different one.',
        'project_created' => 'Workspace created',
    ],

    'security' => [
        'heading' => 'Secure your account',
        'description' => 'Set your own password before you continue. This step is optional — you can change it anytime under your profile.',
        'warning_default' => 'You are still signed in with the default password. Please set your own password now.',
        'card_label' => 'Change password',
        'new_label' => 'New password',
        'new_placeholder' => 'At least 8 characters',
        'confirm_label' => 'Confirm password',
        'confirm_placeholder' => 'Repeat the password',
        'avatar_button' => 'Upload picture',
        'save_button' => 'Update password',
        'optional_hint' => 'Optional — skip with “Continue” to set it up later under your profile.',
    ],

    'agents' => [
        'heading' => 'Your Claude subscription',
        'description' => 'Tasks run on the subscription of the person who owns them. Add yours — the first workspace\'s setup runs on it too. Other members add their own in their profile; shared agents for automation are under Agent credentials.',
        'gate_hint' => 'Your Claude subscription is required',
        'claude_label' => 'Claude Code',
        'claude_hint' => 'Generate a token with <code class="text-xs">claude setup-token</code> and paste it below.',
    ],

    'token' => [
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
        'heading' => 'Create the first workspace',
        'description' => 'Authorize a Git host, then pick the repository. A workspace is the project: repository, environment, members and its tasks.',
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
        'loading_repos' => 'Loading repositories…',
        'loading_branches' => 'Loading branches…',
        'no_repos' => 'No repositories found for this source.',
        'branch_label' => 'Default branch',
        'branch_placeholder' => 'Choose a branch…',
        'name_label' => 'Workspace name',
        'create_button' => 'Create workspace',
    ],

    'done' => [
        'heading' => 'Argos is ready',
        'description' => 'Your first workspace exists. Argos is checking the repository now: if the preview contract, the setup steps or a fitting stack are missing, a setup task starts on your subscription — open the workspace to follow it, then start your first task.',
        'create_workspace' => 'Open first workspace',
        'view_project' => 'Workspace settings',
    ],
];
