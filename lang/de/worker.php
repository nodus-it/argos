<?php

declare(strict_types=1);

return [
    'stacks' => [
        'label' => 'Stack',
        'plural' => 'Stacks',
        'sections' => [
            'definition' => 'Definition',
            'definition_description' => 'Identität & Fähigkeiten des Stacks — was er kann, welche Tools er bringt, ob er aktiv ist.',
            'dockerfile' => 'Dockerfile',
            'dockerfile_description' => 'Das vollständige Dockerfile, das beim Build zum Stack-Image wird. Tab fügt vier Spaces ein.',
            'status' => 'Status',
        ],
        'fields' => [
            'name' => 'Slug',
            'name_help' => 'Eindeutiger Bezeichner für die DB — Built-ins wie php-8.4 / php-8.3. Bei Kopien automatisch mit „-copy" suffigiert.',
            'label' => 'Anzeigename',
            'label_help' => 'Lesbar für Menschen — taucht in Stack-Selects in Tasks/Projekten auf.',
            'is_builtin' => 'Built-in',
            'is_builtin_help' => 'Built-ins werden bei jedem migrate aus dem Argos-Manifest gespiegelt. Eigene Stacks bleiben unverändert.',
            'base_image' => 'Base-Image',
            'base_image_help' => 'Reference-only Hinweis. Das tatsächliche FROM steht im Dockerfile — dieses Feld dokumentiert es für die Übersicht (z.B. php:8.4-cli-bookworm).',
            'capabilities' => 'Capabilities',
            'capabilities_help' => 'Sprach-/Tool-Fähigkeiten als Tags — z.B. php, composer, node. Agents prüfen daran, ob sie auf diesem Stack laufen können (claude-code braucht z.B. node).',
            'common_tools' => 'Common Tools',
            'common_tools_help' => 'Reine Doku-Tags der zusätzlich installierten Werkzeuge (git, jq, gh …). Werden nicht für Validation benutzt — informativ für Stack-Auswahl.',
            'dockerfile_body' => 'Dockerfile',
            'status' => 'Status',
            'status_help' => 'Disabled-Stacks werden vom Resolver übersprungen, auch wenn ein Task/Projekt sie noch referenziert.',
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
        'notifications' => [
            'duplicated' => 'Stack als „:name" dupliziert.',
            'build_dispatched' => ':count Image-Build(s) in der Queue.',
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
        'build_log_description' => 'Stdout/stderr aus dem Stack-Build, dem Worker-Layer und dem Post-Build-Validate-Step.',
        'empty_log' => 'Kein Build-Log vorhanden.',
        'lines' => 'Zeilen',
        'fields' => [
            'tag' => 'Tag',
            'stack' => 'Stack',
            'agent' => 'Agent',
            'status' => 'Status',
            'size_bytes' => 'Größe',
            'built_at' => 'Gebaut am',
            'build_log' => 'Build-Log',
            'outdated' => 'Update verfügbar',
        ],
        'filters' => [
            'outdated_all' => 'Alle',
            'outdated_only' => 'Nur veraltete',
            'outdated_current' => 'Nur aktuelle',
        ],
        'actions' => [
            'rebuild' => 'Neu bauen',
            'rebuild_dispatched' => 'Build-Job in der Queue.',
            'rebuild_all_outdated' => 'Alle veralteten neu bauen',
            'rebuild_all_outdated_confirm' => ':count Builds neu anstoßen?',
            'rebuild_all_outdated_dispatched' => ':count Build-Job(s) in der Queue.',
        ],
    ],

    'updates' => [
        'widget_label' => 'Worker-Updates verfügbar',
        'no_updates' => 'Alle Worker-Images sind aktuell.',
    ],
];
