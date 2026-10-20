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
        'password_help_create' => 'Initial password, at least 8 characters. The user can change it in their profile.',
        'password_help_edit' => 'Leave empty to keep the current password.',
        'locale' => 'Language',
        'is_admin' => 'Administrator',
        'is_admin_help' => 'Administrators manage users, agents, worker stacks, OAuth apps and settings, and see every workspace.',
        'created_at' => 'Created',
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
