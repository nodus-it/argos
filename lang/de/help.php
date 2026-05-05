<?php

declare(strict_types=1);

return [
    'learn_more' => 'Mehr erfahren',
    'docs' => 'Docs',

    'platform' => [
        'github' => [
            'title' => 'GitHub-Setup',
            'body' => 'Du brauchst nur ein Personal Access Token mit `repo`-Scope. Token läuft ab? Einfach neu erstellen und im Projekt aktualisieren.',
            'link_label' => 'Token erstellen',
            'link_url' => ':github_pat',
            'doc_label' => 'Vollständige Anleitung',
            'doc_url' => ':setup_github',
        ],
        'gitlab' => [
            'title' => 'GitLab-Setup',
            'body' => 'Funktioniert auch mit Self-Hosted GitLab — setze dafür `GITLAB_INSTANCE_URL` in deiner Env. Token braucht `api` und `write_repository`.',
            'link_label' => 'Token erstellen',
            'link_url' => ':gitlab_pat',
            'doc_label' => 'Vollständige Anleitung',
            'doc_url' => ':setup_gitlab',
        ],
        'bitbucket' => [
            'title' => 'Bitbucket-Setup',
            'body' => 'Bitbucket nutzt App Passwords statt PATs. Token-Format: <code>username:app_password</code> (nicht dein Account-Passwort).',
            'link_label' => 'App Password erstellen',
            'link_url' => ':bitbucket_app_passwords',
            'doc_label' => 'Vollständige Anleitung',
            'doc_url' => ':setup_bitbucket',
        ],
    ],

    'oauth' => [
        'available' => [
            'title' => 'OAuth verfügbar',
            'body' => 'Du hast bereits einen verbundenen Account — wechsle bei "Authentifizierung" zu OAuth, um Repos und Branches direkt aus Dropdowns zu wählen statt URLs einzugeben.',
        ],
        'connected' => [
            'title' => 'Account verbunden',
            'body' => 'Repos und Branches werden automatisch aus deinem verbundenen Account geladen.',
        ],
        'not_configured' => [
            'title' => 'OAuth nicht eingerichtet',
            'body' => 'Du kannst Projekte trotzdem per Personal Access Token anlegen. OAuth aktiviert Repo-/Branch-Dropdowns und Multi-User-Setups.',
            'doc_label' => 'Wann lohnt sich OAuth?',
            'doc_url' => ':oauth',
        ],
        'overview' => [
            'title' => 'Was bringt mir OAuth?',
            'body' => 'Verbundene Accounts ersetzen Personal Access Tokens auf Projekt-Ebene. Du wählst Repos und Branches aus Dropdowns statt URLs einzutippen, und jeder User kann eigene Accounts verbinden.',
            'doc_label' => 'OAuth-Übersicht',
            'doc_url' => ':oauth',
        ],
    ],

    'task' => [
        'description' => [
            'title' => 'Tipps für gute Task-Beschreibungen',
            'body' => 'Konkrete Akzeptanzkriterien führen zu besseren PRs. Schreibe was getan werden soll, *warum*, und woran du das Ergebnis erkennst. Lass den User-Story-Style weg — der Agent ist kein PM.',
        ],
        'no_projects' => [
            'title' => 'Noch kein Projekt',
            'body' => 'Du brauchst zuerst ein Projekt mit Repo und Token, bevor du einen Task anlegen kannst.',
        ],
    ],

    'claude_token' => [
        'expiry' => [
            'title' => 'Token-Ablauf',
            'body' => 'Der Claude-Token läuft nach einigen Wochen ab. Wenn Tasks plötzlich mit "401 Unauthorized" fehlschlagen: <code>claude setup-token</code> erneut ausführen und neu eintragen.',
            'doc_label' => 'Claude Code Quickstart',
            'doc_url' => ':claude_setup_token',
        ],
    ],

    'configuration' => [
        'env_reference' => [
            'title' => 'Welche Variablen kann ich setzen?',
            'body' => 'Die vollständige Liste aller Environment-Variablen mit Defaults findest du in der Doku.',
            'doc_label' => 'Configuration Reference',
            'doc_url' => ':configuration',
        ],
    ],
];
