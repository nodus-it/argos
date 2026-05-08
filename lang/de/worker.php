<?php

declare(strict_types=1);

return [
    'stacks' => [
        'label' => 'Stack',
        'plural' => 'Stacks',
        'sections' => [
            'definition' => 'Definition',
            'dockerfile' => 'Dockerfile',
            'metadata' => 'Metadaten',
            'status' => 'Status',
        ],
        'fields' => [
            'name' => 'Slug',
            'name_help' => 'Eindeutiger Bezeichner — Built-ins wie php-8.4 / php-8.3',
            'label' => 'Anzeigename',
            'is_builtin' => 'Built-in',
            'base_image' => 'Base-Image',
            'base_image_help' => 'Docker-Base-Image (z.B. php:8.4-cli-bookworm)',
            'capabilities' => 'Capabilities',
            'capabilities_help' => 'Liste der Sprach-/Tool-Fähigkeiten — z.B. php, composer, node. Agents validieren ihre requires_stack_capabilities dagegen.',
            'common_tools' => 'Common Tools',
            'dockerfile_body' => 'Dockerfile',
            'status' => 'Status',
            'installed_version' => 'Installierte Version',
            'upstream_version' => 'Upstream-Version',
            'has_update' => 'Update verfügbar',
            'last_built_at' => 'Zuletzt gebaut',
            'last_checked_at' => 'Zuletzt geprüft',
        ],
        'actions' => [
            'duplicate' => 'Duplizieren',
        ],
        'notices' => [
            'builtin_readonly' => 'Built-in-Stacks sind read-only. Zum Anpassen erst duplizieren.',
        ],
    ],

    'agents' => [
        'label' => 'Agent',
        'plural' => 'Agents',
        'fields' => [
            'name' => 'Slug',
            'label' => 'Anzeigename',
            'npm_pkg' => 'npm-Paket',
            'pinned_version' => 'Pin',
            'requires_stack_capabilities' => 'Erforderliche Stack-Capabilities',
            'config_schema' => 'Config-Schema',
        ],
    ],

    'credentials' => [
        'label' => 'Agent-Credential',
        'plural' => 'Agent-Credentials',
        'sections' => [
            'identity' => 'Identität',
            'auth' => 'Authentifizierung',
        ],
        'fields' => [
            'agent_name' => 'Agent',
            'name' => 'Beschreibung',
            'name_help' => 'z.B. „Persönlich" oder „Team-Account"',
            'status' => 'Status',
            'last_validated_at' => 'Zuletzt geprüft',
            'token' => 'OAuth-Token',
            'token_help' => 'Output von `claude setup-token`',
            'auth_json' => 'auth.json (Inhalt)',
            'auth_json_help' => 'Inhalt deiner ~/.codex/auth.json — wird verschlüsselt in der DB abgelegt.',
        ],
    ],

    'image_builds' => [
        'label' => 'Image-Build',
        'plural' => 'Image-Builds',
        'fields' => [
            'tag' => 'Tag',
            'stack' => 'Stack',
            'agent' => 'Agent',
            'status' => 'Status',
            'size_bytes' => 'Größe',
            'built_at' => 'Gebaut am',
            'build_log' => 'Build-Log',
        ],
        'actions' => [
            'rebuild' => 'Neu bauen',
            'rebuild_dispatched' => 'Build-Job in der Queue.',
        ],
    ],

    'updates' => [
        'widget_label' => 'Worker-Updates verfügbar',
        'no_updates' => 'Alle Worker-Images sind aktuell.',
    ],
];
