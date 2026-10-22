<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Select and filter options from the enum's own label(): value => label.
 * (The fields keep plain string values, as the forms expect.)
 */
trait ProvidesOptions
{
    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
