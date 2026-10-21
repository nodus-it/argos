<?php

declare(strict_types=1);

return [
    'worker_source' => [
        'standard' => 'Standard',
        'byoi' => 'Eigenes Image (BYOI)',
        'devcontainer' => 'Devcontainer',
    ],

    'worker_image_entity_status' => [
        'active' => 'Aktiv',
        'deprecated' => 'Veraltet',
        'disabled' => 'Deaktiviert',
    ],

    'worker_image_build_status' => [
        'queued' => 'Wartet',
        'building' => 'Build läuft',
        'ready' => 'Bereit',
        'failed' => 'Fehlgeschlagen',
    ],

    'agent_credential_status' => [
        'active' => 'Aktiv',
        'expired' => 'Abgelaufen',
        'revoked' => 'Widerrufen',
    ],

    'provider_credential_status' => [
        'active' => 'Aktiv',
        'expired' => 'Abgelaufen',
        'revoked' => 'Widerrufen',
    ],

    'demo_status' => [
        'building' => 'Wird gebaut',
        'live' => 'Live',
        'failed' => 'Fehlgeschlagen',
        'stopped' => 'Gestoppt',
    ],
    'task_provider_mode' => [
        'webhook' => 'Webhook (Push)',
        'poll' => 'Polling',
        'disabled' => 'Deaktiviert',
    ],

    'task_provider_sync_status' => [
        'pending' => 'Ausstehend',
        'active' => 'Aktiv',
        'error' => 'Fehler',
    ],

    'workspace_status' => [
        'provisioning' => 'Wird eingerichtet',
        'starting' => 'Startet',
        'running' => 'Läuft',
        'stopping' => 'Stoppt',
        'stopped' => 'Gestoppt',
        'failed' => 'Fehlgeschlagen',
    ],

    'workspace_role' => [
        'owner' => 'Besitzer',
        'editor' => 'Bearbeiter',
        'viewer' => 'Leser',
    ],

    'gate_run_status' => [
        'running' => 'Läuft',
        'passed' => 'Bestanden',
        'failed' => 'Fehlgeschlagen',
        'error' => 'Fehler',
    ],
    'auto_thread_state' => [
        'queued' => 'Wartet',
        'planning' => 'Plant',
        'awaiting_plan_approval' => 'Plan wartet auf Freigabe',
        'implementing' => 'Setzt um',
        'gating' => 'Prüft Gates',
        'fixing' => 'Behebt Gate-Fehler',
        'shipping' => 'Liefert aus',
        'done' => 'Fertig',
        'paused' => 'Pausiert',
        'failed' => 'Fehlgeschlagen',
        'budget_exceeded' => 'Budget überschritten',
    ],
    'auto_plan_mode' => [
        'off' => 'Kein Plan — direkt umsetzen',
        'auto' => 'Planen, dann automatisch weiter',
        'approve' => 'Planen, dann auf Freigabe warten',
    ],
    'auto_ship_mode' => [
        'pr' => 'Pull Request',
        'draft_pr' => 'Entwurfs-Pull-Request',
        'none' => 'Nur committen und pushen',
    ],
    'workspace_origin' => [
        'manual' => 'Manuell',
        'api' => 'API',
        'mcp' => 'MCP',
        'issue' => 'Issue',
    ],
    'thread_status' => [
        'closed' => 'Geschlossen',
        'starting' => 'Startet',
        'idle' => 'Bereit',
        'running' => 'Arbeitet',
        'waiting_input' => 'Wartet auf Eingabe',
        'error' => 'Fehler',
    ],

    'permission_mode' => [
        'default' => 'Vor Änderungen fragen',
        'acceptEdits' => 'Änderungen annehmen',
        'plan' => 'Plan-Mode',
        'bypassPermissions' => 'Freigaben umgehen',
    ],

    'approval_kind' => [
        'tool' => 'Tool-Aufruf',
        'plan' => 'Plan',
        'question' => 'Frage',
    ],

    'approval_status' => [
        'pending' => 'Offen',
        'allowed' => 'Erlaubt',
        'denied' => 'Abgelehnt',
        'answered' => 'Beantwortet',
        'cancelled' => 'Abgebrochen',
    ],
    'review_comment_status' => [
        'draft' => 'Gesammelt',
        'stale' => 'Veraltet',
        'sent' => 'Gesendet',
        'discarded' => 'Verworfen',
    ],
];
