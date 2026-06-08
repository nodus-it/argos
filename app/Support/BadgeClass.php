<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maps a Filament colour name (as returned by enum color() methods) onto the
 * redesign's badge CSS class, so views don't repeat the lookup inline.
 */
class BadgeClass
{
    private const MAP = [
        'success' => 'badge-success',
        'warning' => 'badge-running',
        'danger' => 'badge-failed',
        'gray' => 'badge-draft',
    ];

    public static function for(string $color): string
    {
        return self::MAP[$color] ?? 'badge-draft';
    }
}
