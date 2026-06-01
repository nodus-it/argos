<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Einrichtung',
    'title' => 'Argos einrichten',

    'nav' => [
        'next' => 'Weiter',
        'back' => 'Zurück',
    ],

    'steps' => [
        'agents' => 'Agenten',
        'repository' => 'Repository',
        'done' => 'Fertig',
    ],

    'notifications' => [
        'env_token' => 'Token kommt aus der Umgebungsvariable',
        'empty_token' => 'Bitte einen Token eingeben',
        'invalid_token_title' => 'Token ungültig',
        'invalid_token_body' => 'Der eingegebene Token wurde von der API abgelehnt.',
        'saved_title' => 'Token gespeichert',
        'saved_unreachable_body' => 'Hinweis: Token konnte nicht gegen die API geprüft werden — Verbindung nicht erreichbar.',
        'empty_codex' => 'Bitte den Inhalt von auth.json einfügen',
        'invalid_codex_title' => 'auth.json ungültig',
        'invalid_codex_body' => 'Der eingefügte Inhalt ist kein gültiges JSON.',
        'codex_saved' => 'Codex-Credential gespeichert',
        'disconnected' => ':provider getrennt',
        'need_agent' => 'Bitte zuerst mindestens einen Agenten authentifizieren',
        'repo_incomplete' => 'Bitte Repository und Branch wählen',
        'name_taken_title' => 'Name bereits vergeben',
        'name_taken_body' => 'Ein Projekt mit diesem Namen existiert bereits — bitte einen anderen wählen.',
        'project_created' => 'Projekt angelegt',
    ],

    'agents' => [
        'heading' => 'Welchen Agenten möchtest du nutzen?',
        'description' => 'Authentifiziere mindestens einen Coding-Agenten. Weitere kannst du später ergänzen oder ändern.',
        'gate_hint' => 'Mindestens ein Agent erforderlich',
        'claude_label' => 'Claude Code',
        'claude_hint' => 'Token via <code class="text-xs">claude setup-token</code> erzeugen und unten einfügen.',
        'codex_label' => 'OpenAI Codex',
        'codex_hint' => '<code class="text-xs">codex login</code> ausführen, dann Inhalt von <code class="text-xs">~/.codex/auth.json</code> unten einfügen.',
        'codex_placeholder' => '{"OPENAI_API_KEY": null, "tokens": {…}, …}',
        'codex_saved_short' => 'Gespeichert — neuen Inhalt einfügen, um zu überschreiben.',
    ],

    'token' => [
        'from_env' => 'Token kommt aus der Umgebungsvariable <code class="text-xs">CLAUDE_CODE_OAUTH_TOKEN</code> — nichts zu tun.',
        'is_saved_short' => 'Gespeichert — neuen Token einfügen, um zu überschreiben.',
        'placeholder' => 'sk-ant-oat01-…',
        'save_button' => 'Speichern',
    ],

    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        'connect_button' => 'Mit :provider verbinden',
        'disconnect' => 'Trennen',
    ],

    'repo' => [
        'heading' => 'Repository verbinden',
        'description' => 'Autorisiere einen Git-Host und wähle dann das Repository, an dem Argos arbeiten soll.',
        'authorize_heading' => '1 · Zugang autorisieren',
        'oauth_card_title' => 'OAuth',
        'oauth_card_desc' => 'Mit einem Klick verbinden — danach Repos und Branches bequem per Dropdown wählen.',
        'oauth_none' => 'Noch keine OAuth-App hinterlegt.',
        'pat_card_title' => 'Access Token (PAT)',
        'pat_card_desc' => 'Token direkt hinterlegen — funktioniert auch für self-hosted GitLab und GitHub Enterprise.',
        'pat_link' => 'Token hinterlegen',
        'oauth_app_link' => 'OAuth-App einrichten',
        'oauth_add_more' => 'Weitere OAuth-App hinzufügen',
        'pick_heading' => '2 · Repository wählen',
        'source_label' => 'Quelle',
        'source_placeholder' => 'Verbundenen Account oder Token wählen…',
        'group_oauth' => 'Verbundene Accounts',
        'group_pat' => 'Access-Tokens (PAT)',
        'repo_label' => 'Repository',
        'repo_placeholder' => 'Repository wählen…',
        'no_repos' => 'Keine Repositories für diese Quelle gefunden.',
        'branch_label' => 'Standard-Branch',
        'branch_placeholder' => 'Branch wählen…',
        'name_label' => 'Projektname',
        'create_button' => 'Projekt anlegen',
    ],

    'done' => [
        'heading' => 'Argos ist startklar',
        'description' => 'Dein erstes Projekt ist verbunden. Lege eine Aufgabe an, damit ein Agent aus einem Plan einen Pull Request macht.',
        'create_task' => 'Erste Aufgabe anlegen',
        'view_project' => 'Projekt ansehen',
    ],
];
