<?php

declare(strict_types=1);

namespace App\Enums;

enum GitProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GitHub => 'gray',
            self::GitLab => 'warning',
            self::Bitbucket => 'info',
        };
    }
}
