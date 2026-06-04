<?php

// Closed-deployment app. OAuth client credentials are managed entirely in the
// UI (Configuration → OAuth Apps) and stored in the `provider_oauth_configs`
// table; OAuthConfigHydrator mirrors the enabled public-instance rows onto
// these keys at boot. There is no ENV path — the empty defaults below mean
// "not configured" until a row exists. Callback paths are fixed; Socialite
// resolves the relative path against APP_URL.

return [

    'github' => [
        'client_id' => null,
        'client_secret' => null,
        'redirect' => '/auth/github/callback',
    ],

    'gitlab' => [
        'client_id' => null,
        'client_secret' => null,
        'redirect' => '/auth/gitlab/callback',
        // socialiteproviders/gitlab concatenates this verbatim with 'oauth/authorize',
        // so the trailing slash must be guaranteed. The hydrator overwrites this
        // with the DB instance_url; gitlab.com is the public-instance default.
        'instance_uri' => 'https://gitlab.com/',
    ],

    'bitbucket' => [
        'client_id' => null,
        'client_secret' => null,
        'redirect' => '/auth/bitbucket/callback',
    ],

    'linear' => [
        'client_id' => null,
        'client_secret' => null,
        'redirect' => '/auth/linear/callback',
    ],

];
