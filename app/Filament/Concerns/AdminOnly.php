<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Restricts a Filament resource or page to platform administrators (the
 * `administer-argos` gate). Hides it from navigation and answers 403 on
 * direct access.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        return Gate::allows('administer-argos');
    }
}
