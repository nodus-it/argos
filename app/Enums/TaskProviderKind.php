<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskProviderKind: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Linear = 'linear';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Linear => 'Linear',
        };
    }

    /** Returns the ConnectedAccount provider key for OAuth lookup. */
    public function providerKey(): ?string
    {
        return match ($this) {
            self::GitHub => 'github',
            self::GitLab => 'gitlab',
            self::Linear => null,
        };
    }
}
