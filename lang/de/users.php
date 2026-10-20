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
        'password_help_create' => 'Initial-Passwort, mindestens 8 Zeichen. Der Benutzer kann es im Profil ändern.',
        'password_help_edit' => 'Leer lassen, um das aktuelle Passwort zu behalten.',
        'locale' => 'Sprache',
        'is_admin' => 'Administrator',
        'is_admin_help' => 'Administratoren verwalten Benutzer, Agenten, Worker-Stacks, OAuth-Apps und Einstellungen und sehen alle Workspaces.',
        'created_at' => 'Angelegt',
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
