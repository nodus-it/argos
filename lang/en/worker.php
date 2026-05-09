<?php

declare(strict_types=1);

return [
    'stacks' => [
        'label' => 'Stack',
        'plural' => 'Stacks',
        'sections' => [
            'definition' => 'Definition',
            'dockerfile' => 'Dockerfile',
            'metadata' => 'Metadata',
            'status' => 'Status',
        ],
        'fields' => [
            'name' => 'Slug',
            'name_help' => 'Unique identifier — built-ins are e.g. php-8.4 / php-8.3',
            'label' => 'Display Name',
            'is_builtin' => 'Built-in',
            'base_image' => 'Base image',
            'base_image_help' => 'Docker base image (e.g. php:8.4-cli-bookworm)',
            'capabilities' => 'Capabilities',
            'capabilities_help' => 'Language / tool capabilities — e.g. php, composer, node. Agents validate their requires_stack_capabilities against this.',
            'common_tools' => 'Common tools',
            'dockerfile_body' => 'Dockerfile',
            'status' => 'Status',
            'installed_version' => 'Installed version',
            'upstream_version' => 'Upstream version',
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
            'auth' => 'Authentication',
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
