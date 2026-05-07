<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthMethod: string
{
    case Pat = 'pat';
    case OAuth = 'oauth';

    public function label(): string
    {
        return match ($this) {
            self::Pat => 'PAT',
            self::OAuth => 'OAuth',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pat => 'gray',
            self::OAuth => 'success',
        };
    }
}
