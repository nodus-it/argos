<?php

declare(strict_types=1);

namespace App\Enums;

/** Outcome of an approval request. */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Answered = 'answered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.approval_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Allowed => 'success',
            self::Denied => 'danger',
            self::Answered => 'success',
            self::Cancelled => 'gray',
        };
    }
}
