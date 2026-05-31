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
