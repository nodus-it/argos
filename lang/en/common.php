<?php

declare(strict_types=1);

return [
    'status' => [
        'paused' => 'Paused',
        'lock_blocked' => 'Lock blocked',
    ],

    'profile' => [
        'locale_label' => 'Language',
        'locale_en' => 'English',
        'locale_de' => 'Deutsch',
        'section_account' => 'Account',
        'section_account_description' => 'Your name, email address, and preferred language.',
        'section_password' => 'Password',
        'section_password_description' => 'Set a new password. Your current password is required to confirm the change.',
        'section_auto' => 'Automatic tasks',
        'section_auto_description' => 'Whether issues assigned to you may start tasks on your own Claude subscription without you starting them.',
        'auto_task_consent' => 'Issues assigned to me may start automatically on my subscription',
        'auto_task_consent_help' => 'When a workspace picks up an issue assigned to you, the task runs as yours and uses your Claude subscription. Off: such issues wait until you start them.',
        'section_agents' => 'My Claude subscriptions',
        'section_agents_description' => 'Your own agents. Your tasks run on them; shared agents are managed by administrators.',
        'agents_empty' => 'You have no agent of your own yet. Add your Claude subscription to run tasks on it.',
        'agents_limited' => 'Usage limit until :time',
        'agents_add' => 'Add subscription',
        'agents_manage' => 'Manage agents',
    ],

    'source_footer' => 'Argos :version is free software, licensed under :license. :source.',
    'source_link_label' => 'View source code',

    'feedback_button' => [
        'label' => 'Feedback',
        'tooltip' => 'Report a bug or send feedback',
    ],
];
