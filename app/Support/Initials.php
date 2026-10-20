<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/** Avatar initials from a display name ("Ada Lovelace" → "AL"). */
final class Initials
{
    public static function of(?string $name): string
    {
        $initials = Str::of((string) $name)->squish()->explode(' ')
            ->filter()
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials !== '' ? $initials : '?';
    }
}
