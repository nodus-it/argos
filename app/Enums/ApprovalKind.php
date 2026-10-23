<?php

declare(strict_types=1);

namespace App\Enums;

/** What a pending approval asks for: a tool call, a plan, or an answer to a question. */
enum ApprovalKind: string
{
    case Tool = 'tool';
    case Plan = 'plan';
    case Question = 'question';

    public function label(): string
    {
        return __('enums.approval_kind.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Tool => 'warning',
            self::Plan => 'primary',
            self::Question => 'info',
        };
    }
}
