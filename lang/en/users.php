<?php

declare(strict_types=1);

return [
    'label' => 'User',
    'plural' => 'Users',
    'sections' => [
        'account' => 'Account',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_help_create' => 'Optional. Leave empty and hand over an access link instead (key icon in the user list) — the person sets their own password.',
        'password_help_edit' => 'Leave empty to keep the current password.',
        'locale' => 'Language',
        'is_admin' => 'Administrator',
        'is_admin_help' => 'Administrators manage users, agents, worker stacks, OAuth apps and settings, and see every workspace.',
        'created_at' => 'Created',
    ],
    'access_link' => [
        'action' => 'Access link',
        'heading' => 'Access link for :name',
        'description' => 'Send this message to :name (chat, mail). The link sets a new password, works once and expires after :hours hours; creating a new link voids the old one.',
        'text' => 'Welcome message',
        'created' => 'User created — now hand over an access link (key icon).',
        'no_mail' => 'Argos sends no mail here. Ask an administrator for an access link to set a new password.',
        'welcome' => "Hi :name,\n\nyou now have an account on :app (:url).\n\n1. Set your password: :link\n   (sign in with :email; the link works once and expires after :hours hours)\n2. Under My account → Claude subscriptions, add your Claude subscription: run `claude setup-token` in a terminal and paste the token.\n3. Ask an Argos administrator to add you to a workspace — then start a task there.\n\nThe documentation is under Docs in the app.",
    ],
    'errors' => [
        'last_admin' => 'At least one administrator must remain.',
        'cannot_demote_self' => 'You cannot remove your own administrator role.',
    ],
    'command' => [
        'not_found' => 'No user with email :email.',
        'granted' => ':email is now an administrator.',
        'revoked' => ':email is no longer an administrator.',
    ],
];
