<?php

declare(strict_types=1);

return [
    'label' => 'Benutzer',
    'plural' => 'Benutzer',
    'sections' => [
        'account' => 'Konto',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'password_help_create' => 'Optional. Leer lassen und stattdessen einen Zugangslink übergeben (Schlüssel-Symbol in der Liste) – die Person setzt ihr Passwort selbst.',
        'password_help_edit' => 'Leer lassen, um das aktuelle Passwort zu behalten.',
        'locale' => 'Sprache',
        'is_admin' => 'Administrator',
        'is_admin_help' => 'Administratoren verwalten Benutzer, Agenten, Worker-Stacks, OAuth-Apps und Einstellungen und sehen alle Workspaces.',
        'created_at' => 'Angelegt',
    ],
    'access_link' => [
        'action' => 'Zugangslink',
        'heading' => 'Zugangslink für :name',
        'description' => 'Schick diese Nachricht an :name (Chat, Mail). Der Link setzt ein neues Passwort, funktioniert einmal und läuft nach :hours Stunden ab; ein neuer Link macht den alten ungültig.',
        'text' => 'Willkommensnachricht',
        'created' => 'Person angelegt – jetzt einen Zugangslink übergeben (Schlüssel-Symbol).',
        'no_mail' => 'Argos verschickt hier keine Mails. Bitte eine:n Admin um einen Zugangslink, um ein neues Passwort zu setzen.',
        'welcome' => "Hallo :name,\n\ndu hast jetzt einen Zugang zu :app (:url).\n\n1. Passwort setzen: :link\n   (Anmeldung mit :email; der Link funktioniert einmal und läuft nach :hours Stunden ab)\n2. Unter Mein Konto → Claude-Abos dein Claude-Abo hinterlegen: im Terminal `claude setup-token` ausführen und den Token einfügen.\n3. Eine:n Argos-Admin bitten, dich zu einem Workspace hinzuzufügen – dort startest du dann eine Aufgabe.\n\nDie Dokumentation findest du in der App unter Docs.",
    ],
    'errors' => [
        'last_admin' => 'Mindestens ein Administrator muss bestehen bleiben.',
        'cannot_demote_self' => 'Du kannst dir die Administrator-Rolle nicht selbst entziehen.',
    ],
    'command' => [
        'not_found' => 'Kein Benutzer mit der E-Mail :email.',
        'granted' => ':email ist jetzt Administrator.',
        'revoked' => ':email ist kein Administrator mehr.',
    ],
];
