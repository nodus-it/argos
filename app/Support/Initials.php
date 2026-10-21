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

    /**
     * Short workspace badge for the rail, 2–3 letters: the initials of up to
     * three words ("Argos Web App" → "AWA", "shop-api" → "SA"), or the first
     * two letters of a single word ("argos" → "AR").
     */
    public static function abbreviate(?string $name): string
    {
        $words = Str::of((string) $name)
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->squish()
            ->explode(' ')
            ->filter(fn (string $word): bool => $word !== '')
            ->values();
        if ($words->isEmpty()) {
            return '?';
        }
        if ($words->count() === 1) {
            return mb_strtoupper(mb_substr($words->first(), 0, 2));
        }

        return $words->take(3)->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
    }
}
