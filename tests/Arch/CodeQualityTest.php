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
