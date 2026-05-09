<?php

declare(strict_types=1);

namespace App\Enums;

enum ClaudeModel: string
{
    case Opus47 = 'claude-opus-4-7';
    case Sonnet46 = 'claude-sonnet-4-6';
    case Haiku45 = 'claude-haiku-4-5-20251001';

    public function label(): string
    {
        return match ($this) {
            self::Opus47 => 'Claude Opus 4.7',
            self::Sonnet46 => 'Claude Sonnet 4.6',
            self::Haiku45 => 'Claude Haiku 4.5',
        };
    }

    public static function default(string $phase): self
    {
        return match ($phase) {
            'concept' => self::Opus47,
            'implement' => self::Sonnet46,
            default => self::Haiku45,
        };
    }
}
