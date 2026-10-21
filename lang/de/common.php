<?php

declare(strict_types=1);

return [
    'status' => [
        'paused' => 'Pausiert',
        'lock_blocked' => 'Lock blockiert',
    ],

    'profile' => [
        'locale_label' => 'Sprache',
        'locale_en' => 'English',
        'locale_de' => 'Deutsch',
        'section_account' => 'Konto',
        'section_account_description' => 'Dein Name, deine E-Mail-Adresse und die bevorzugte Sprache.',
        'section_password' => 'Passwort',
        'section_password_description' => 'Lege ein neues Passwort fest. Zur Bestätigung ist dein aktuelles Passwort nötig.',
        'section_auto' => 'Automatische Aufgaben',
        'section_auto_description' => 'Ob dir zugewiesene Issues Aufgaben auf deinem eigenen Claude-Abo starten dürfen, ohne dass du sie startest.',
        'auto_task_consent' => 'Mir zugewiesene Issues dürfen automatisch auf meinem Abo starten',
        'auto_task_consent_help' => 'Greift ein Workspace ein dir zugewiesenes Issue auf, läuft die Aufgabe als deine und nutzt dein Claude-Abo. Aus: Die Aufgabe läuft als Automatik-Nutzer des Workspaces, und du wirst eingeladen.',
        'section_agents' => 'Meine Claude-Abos',
        'section_agents_description' => 'Deine eigenen Agenten. Deine Aufgaben laufen darauf; geteilte Agenten verwalten Admins.',
        'agents_empty' => 'Du hast noch keinen eigenen Agenten. Hinterlege dein Claude-Abo, um Aufgaben darauf laufen zu lassen.',
        'agents_limited' => 'Nutzungslimit bis :time',
        'agents_add' => 'Abo hinzufügen',
        'agents_manage' => 'Agenten verwalten',
    ],

    'source_footer' => 'Argos :version ist freie Software unter der :license-Lizenz. :source.',
    'source_link_label' => 'Quellcode ansehen',

    'feedback_button' => [
        'label' => 'Feedback',
        'tooltip' => 'Bug melden oder Feedback geben',
    ],
];
