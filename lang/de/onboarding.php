<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Einrichtung',
    'title' => 'Argos einrichten',

    'nav' => [
        'next' => 'Weiter',
        'back' => 'Zurück',
    ],

    'language' => 'Sprache',

    'steps' => [
        'security' => 'Konto',
        'agents' => 'Claude-Abo',
        'repository' => 'Workspace',
        'done' => 'Fertig',
    ],

    'notifications' => [
        'password_invalid_title' => 'Passwort nicht geändert',
        'password_saved' => 'Passwort aktualisiert',
        'empty_token' => 'Bitte einen Token eingeben',
        'invalid_token_title' => 'Token ungültig',
        'invalid_token_body' => 'Der eingegebene Token wurde von der API abgelehnt.',
        'avatar_saved' => 'Profilbild gespeichert',
        'saved_title' => 'Token gespeichert',
        'saved_unreachable_body' => 'Hinweis: Token konnte nicht gegen die API geprüft werden — Verbindung nicht erreichbar.',
        'disconnected' => ':provider getrennt',
        'need_agent' => 'Hinterlege zuerst dein Claude-Abo',
        'repo_incomplete' => 'Bitte Repository und Branch wählen',
        'name_taken_title' => 'Name bereits vergeben',
        'name_taken_body' => 'Ein Workspace mit diesem Namen existiert bereits — bitte einen anderen wählen.',
        'project_created' => 'Workspace angelegt',
    ],

    'security' => [
        'heading' => 'Konto absichern',
        'description' => 'Lege ein eigenes Passwort fest, bevor du fortfährst. Dieser Schritt ist optional — du kannst es jederzeit in deinem Profil ändern.',
        'warning_default' => 'Du bist noch mit dem Standard-Passwort angemeldet. Bitte lege jetzt ein eigenes Passwort fest.',
        'card_label' => 'Passwort ändern',
        'new_label' => 'Neues Passwort',
        'new_placeholder' => 'Mindestens 8 Zeichen',
        'confirm_label' => 'Passwort bestätigen',
        'confirm_placeholder' => 'Passwort wiederholen',
        'avatar_button' => 'Bild hochladen',
        'save_button' => 'Passwort aktualisieren',
        'optional_hint' => 'Optional — mit „Weiter" überspringen und später im Profil festlegen.',
    ],

    'agents' => [
        'heading' => 'Dein Claude-Abo',
        'description' => 'Aufgaben laufen auf dem Abo der Person, der sie gehören. Hinterlege deins — auch die Einrichtung des ersten Workspaces läuft darauf. Andere Mitglieder hinterlegen ihres im Profil; geteilte Agenten für Automatik findest du unter Agent-Credentials.',
        'gate_hint' => 'Dein Claude-Abo wird benötigt',
        'claude_label' => 'Claude Code',
        'claude_hint' => 'Token via <code class="text-xs">claude setup-token</code> erzeugen und unten einfügen.',
    ],

    'token' => [
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
        'heading' => 'Ersten Workspace anlegen',
        'description' => 'Autorisiere einen Git-Host und wähle dann das Repository. Ein Workspace ist das Projekt: Repository, Umgebung, Mitglieder und seine Aufgaben.',
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
        'loading_repos' => 'Repositories werden geladen…',
        'loading_branches' => 'Branches werden geladen…',
        'no_repos' => 'Keine Repositories für diese Quelle gefunden.',
        'branch_label' => 'Standard-Branch',
        'branch_placeholder' => 'Branch wählen…',
        'name_label' => 'Workspace-Name',
        'create_button' => 'Workspace anlegen',
    ],

    'done' => [
        'heading' => 'Argos ist startklar',
        'description' => 'Dein erster Workspace steht. Argos prüft jetzt das Repository: Fehlen Preview-Vertrag, Setup-Befehle oder ein passender Stack, startet eine Einrichtungs-Aufgabe auf deinem Abo — öffne den Workspace, um sie zu verfolgen, und starte dann deine erste Aufgabe.',
        'create_workspace' => 'Ersten Workspace öffnen',
        'view_project' => 'Workspace-Einstellungen',
    ],
];
