<?php

declare(strict_types=1);

return [
    'stacks' => [
        'label' => 'Stack',
        'plural' => 'Stacks',
        'sections' => [
            'definition' => 'Definition',
            'definition_description' => 'Identity & capabilities of the stack — what it can do, which tools it ships with, whether it is active.',
            'dockerfile' => 'Dockerfile',
            'dockerfile_description' => 'The full Dockerfile that becomes the stack image at build time. Tab inserts four spaces.',
            'status' => 'Status',
        ],
        'fields' => [
            'name' => 'Slug',
            'name_help' => 'Unique identifier for the DB — built-ins are php-8.4 / php-8.3. Copies are auto-suffixed with "-copy".',
            'label' => 'Display name',
            'label_help' => 'Human-readable — shown in stack selects on tasks and projects.',
            'is_builtin' => 'Built-in',
            'is_builtin_help' => 'Built-ins are mirrored from the Argos manifest on every migrate. User stacks stay untouched.',
            'base_image' => 'Base image',
            'base_image_help' => 'Reference-only hint. The actual FROM lives inside the Dockerfile — this field documents it for the overview (e.g. php:8.4-cli-bookworm).',
            'capabilities' => 'Capabilities',
            'capabilities_help' => 'Language / tool capabilities as tags — e.g. php, composer, node. Agents check this to decide whether they can run on the stack (claude-code needs node, for instance).',
            'common_tools' => 'Common tools',
            'common_tools_help' => 'Documentation-only tags for the additional tools installed (git, jq, gh …). Not used for validation — purely informational for stack selection.',
            'dockerfile_body' => 'Dockerfile',
            'status' => 'Status',
            'status_help' => 'Disabled stacks are skipped by the resolver even when a task or project still references them.',
            'has_update' => 'Update available',
            'last_built_at' => 'Last built',
            'last_checked_at' => 'Last checked',
        ],
        'actions' => [
            'duplicate' => 'Duplicate',
        ],
        'notices' => [
            'builtin_readonly' => 'Built-in stacks are read-only. Duplicate first to customise.',
        ],
        'notifications' => [
            'duplicated' => 'Stack duplicated as ":name".',
            'build_dispatched' => ':count image build(s) queued.',
        ],
    ],

    'agents' => [
        'label' => 'Agent',
        'plural' => 'Agents',
        'fields' => [
            'name' => 'Slug',
            'label' => 'Display Name',
            'npm_pkg' => 'npm package',
            'pinned_version' => 'Pin',
            'requires_stack_capabilities' => 'Required stack capabilities',
            'config_schema' => 'Config schema',
        ],
    ],

    'credentials' => [
        'label' => 'Agent credential',
        'plural' => 'Agent credentials',
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
            'auth_json' => 'auth.json (contents)',
            'auth_json_help' => 'Contents of your ~/.codex/auth.json — stored encrypted in the DB.',
        ],
    ],

    'image_builds' => [
        'label' => 'Image build',
        'plural' => 'Image builds',
        'build_log_description' => 'Stdout/stderr from the stack build, the worker layer, and the post-build validate step.',
        'empty_log' => 'No build log captured.',
        'lines' => 'lines',
        'fields' => [
            'tag' => 'Tag',
            'stack' => 'Stack',
            'agent' => 'Agent',
            'status' => 'Status',
            'size_bytes' => 'Size',
            'built_at' => 'Built at',
            'build_log' => 'Build log',
            'outdated' => 'Update available',
        ],
        'filters' => [
            'outdated_all' => 'All',
            'outdated_only' => 'Outdated only',
            'outdated_current' => 'Current only',
        ],
        'actions' => [
            'rebuild' => 'Rebuild',
            'rebuild_dispatched' => 'Build job queued.',
            'rebuild_all_outdated' => 'Rebuild all outdated',
            'rebuild_all_outdated_confirm' => 'Rebuild :count outdated images?',
            'rebuild_all_outdated_dispatched' => ':count build job(s) queued.',
        ],
    ],

    'updates' => [
        'widget_label' => 'Worker updates available',
        'no_updates' => 'All worker images are up to date.',
    ],
];
