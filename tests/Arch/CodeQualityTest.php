<?php

declare(strict_types=1);

arch('no debug calls in app/')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('strict types in app/')
    ->expect('App')
    ->toUseStrictTypes();

arch('workers are UI-isolated')
    ->expect('App\Workers')
    ->not->toUse('App\Filament');

// The browser-E2E fakes must never be wired into production code paths — only
// the (env-gated, prod-throwing) E2eFakeServiceProvider may reference them.
arch('e2e fakes are not used by production code')
    ->expect('App\Testing')
    ->toOnlyBeUsedIn('App\Providers\E2eFakeServiceProvider');

// Saloon is the transport layer for external APIs and must stay confined to
// app/Integrations. Domain services reach it only through the connectors that
// live there, never by depending on Saloon directly.
arch('saloon is confined to integrations')
    ->expect('Saloon')
    ->toOnlyBeUsedIn('App\Integrations');

// The flip side of the Saloon rule: no raw HTTP client may bypass it. Every
// outbound API call goes through a Saloon connector in app/Integrations, so the
// Laravel HTTP facade and Guzzle must not appear in domain code. (Socialite is
// the one accepted exception for the OAuth *login* flow — it has its own
// abstraction and never touches these symbols.)
arch('no raw http client outside integrations')
    ->expect(['Illuminate\Support\Facades\Http', 'GuzzleHttp\Client'])
    ->toOnlyBeUsedIn('App\Integrations');

// Integrations are pure transport — they must not reach into the UI layer.
arch('integrations are UI-isolated')
    ->expect('App\Integrations')
    ->not->toUse('App\Filament');
