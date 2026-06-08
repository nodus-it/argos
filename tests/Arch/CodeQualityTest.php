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

// Stronger: the transport layer depends only on Saloon, never on the domain.
// Connectors/requests take primitives (tokens, strings) — they must not pull in
// models, services, jobs or any other app layer, so they stay a thin, reusable
// edge that the domain consumes through contracts.
arch('integrations are a pure transport layer')
    ->expect('App\Integrations')
    ->not->toUse([
        'App\Models',
        'App\Services',
        'App\Jobs',
        'App\Workers',
        'App\Listeners',
        'App\Events',
        'App\Http',
        'App\Console',
    ]);

// Concrete provider implementations are an internal detail of their service
// namespace. Everything else (jobs, Filament, workers, …) depends on the
// contracts and resolves through the registries/factory — never on a concrete
// GitHubGitService / LinearIssueTracker / … directly.
arch('concrete git services stay behind the registry')
    ->expect([
        'App\Services\GitProvider\GitHubGitService',
        'App\Services\GitProvider\GitLabGitService',
        'App\Services\GitProvider\BitbucketGitService',
    ])
    ->toOnlyBeUsedIn(['App\Services\GitProvider', 'App\Providers']);

arch('concrete issue trackers stay behind the registry')
    ->expect([
        'App\Services\IssueTracker\GitHubIssueTracker',
        'App\Services\IssueTracker\GitLabIssueTracker',
        'App\Services\IssueTracker\BitbucketIssueTracker',
        'App\Services\IssueTracker\LinearIssueTracker',
    ])
    ->toOnlyBeUsedIn(['App\Services\IssueTracker', 'App\Providers']);

// Saloon request classes are real Saloon requests, so a new request can't
// accidentally be a plain class that bypasses the connector's auth/base-URL.
// (The per-provider namespace list needs a new entry when a provider is added.)
arch('integration requests extend the saloon request base')
    ->expect([
        'App\Integrations\GitHub\Requests',
        'App\Integrations\GitLab\Requests',
        'App\Integrations\Bitbucket\Requests',
        'App\Integrations\Linear\Requests',
        'App\Integrations\Anthropic\Requests',
        'App\Integrations\OAuth\Requests',
    ])
    ->toExtend('Saloon\Http\Request');
