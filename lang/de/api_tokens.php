<?php

declare(strict_types=1);

return [
    'title' => 'API-Tokens',

    'fields' => [
        'name' => 'Bezeichnung',
        'abilities' => 'Berechtigungen',
        'last_used_at' => 'Zuletzt genutzt',
        'created_at' => 'Erstellt',
    ],

    'actions' => [
        'create' => 'Token erstellen',
        'revoke' => 'Widerrufen',
    ],

    'notifications' => [
        'created_title' => 'Token erstellt — jetzt kopieren, wird nur einmal angezeigt',
    ],

    'client' => [
        'run_as' => 'Handelt als',
        'run_as_help' => 'Automatische Läufe mit den Tokens dieses Clients handeln für diese Person: Ihr Claude-Agent arbeitet, und sie besitzt den Workspace. Für die Workspace-Endpunkte erforderlich.',
        'label' => 'API-Client',
        'plural' => 'API-Clients',
        'section' => 'Identität',
        'section_description' => 'Ein wiedererkennbarer Name für diesen programmatischen Zugriff.',
        'name' => 'Name',
        'name_help' => 'Frei wählbarer Name für den programmatischen Zugriff (z. B. „CI", „Skripte"). Token mit Vollzugriff über alle Projekte.',
        'tokens' => 'Tokens',
    ],
];
