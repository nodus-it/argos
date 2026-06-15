<?php

declare(strict_types=1);

return [
    'pat' => [
        'label' => 'Access Token',
        'plural' => 'Access Tokens',
        'sections' => [
            'identity' => 'Identity',
            'identity_description' => 'Platform, an optional instance URL, and a label to recognize it by.',
            'auth' => 'Token',
            'auth_description' => 'The personal access token with the required scopes — stored encrypted, never logged.',
        ],
        'fields' => [
            'label' => 'Label',
            'label_help' => 'A free-form name for recognition, e.g. "GitHub – acme org".',
            'provider' => 'Platform',
            'instance_url' => 'Instance URL',
            'instance_url_help' => 'Only for self-hosted instances (GitLab CE/EE, Bitbucket Server). Leave empty for the public instance.',
            'token' => 'Token',
            'token_help' => 'Personal Access Token with the required scopes. Stored encrypted and never logged.',
            'scopes_hint' => 'Scopes (note)',
            'scopes_hint_help' => 'Optional note on which scopes the token was minted with.',
            'status' => 'Status',
            'last_validated_at' => 'Last validated',
        ],
        'actions' => [
            'test' => 'Test connection',
        ],
        'notifications' => [
            'test_ok' => 'Connection successful',
            'test_failed' => 'Connection failed',
        ],
        'guide' => [
            'button' => 'Create token at :provider',
            'scopes' => 'Required scopes',
            'choose_provider' => 'Choose the platform (and instance URL if self-hosted) first — then a direct link to create a token with the right scopes appears here.',
        ],
    ],

    'oauth' => [
        'label' => 'OAuth App',
        'plural' => 'OAuth Apps',
        'public_instance' => 'Public instance',
        'sections' => [
            'app' => 'OAuth App',
            'app_description' => 'Platform, an optional instance URL, and the callback URL for the OAuth flow.',
            'credentials' => 'Credentials',
            'credentials_description' => "The OAuth app's client ID and client secret — stored encrypted, never logged.",
        ],
        'fields' => [
            'provider' => 'Platform',
            'instance_url' => 'Instance URL',
            'instance_url_help' => 'Only for self-hosted instances. Leave empty for the public instance.',
            'callback_url' => 'Callback URL',
            'callback_url_help' => 'Register this exact URL as the redirect/callback URL in the platform\'s OAuth app.',
            'callback_url_placeholder' => 'Choose a platform first …',
            'client_id' => 'Client ID',
            'client_secret' => 'Client secret',
            'client_secret_help' => 'Stored encrypted and never logged.',
            'enabled' => 'Enabled',
        ],
        'guide' => [
            'button' => 'Create OAuth app at :provider',
            'scopes' => 'Required scopes',
            'choose_provider' => 'Choose the platform (and instance URL if self-hosted) first — then a direct link to create the OAuth app appears here.',
            'callback_note' => 'Register the callback URL above as the redirect URL in the OAuth app.',
            'manual_note' => 'Create the OAuth app under your provider workspace settings (OAuth consumer) and set the callback URL above as the redirect URL.',
        ],
        'refresh' => [
            'expired_no_refresh_token' => 'The OAuth token for :provider has expired and no refresh_token is available — please reconnect the account.',
            'failed' => 'OAuth token refresh for :provider failed (HTTP :status) — please reconnect the account.',
            'no_access_token' => 'OAuth token refresh for :provider returned no access_token — please reconnect the account.',
            'unknown_provider' => 'Unknown OAuth provider for token refresh: :provider',
        ],
    ],

    'verify' => [
        'rejected_title' => 'Credential rejected',
        'unreachable_title' => 'Could not verify credential',
        'oauth_scope_title' => 'Account connected, but the API rejected it',
        'token_rejected' => 'The provider rejected this token. Check it is correct and has the required scopes.',
        'provider_unreachable' => 'The provider could not be reached — the credential was saved but not verified.',
    ],
];
