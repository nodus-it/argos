<?php

declare(strict_types=1);

namespace App\Enums;

/** Life of a collected review comment. */
enum ReviewCommentStatus: string
{
    /** Collected, goes out with the next "send review". */
    case Draft = 'draft';

    /** Claude changed the commented lines afterwards; only sent on request. */
    case Stale = 'stale';

    case Sent = 'sent';

    case Discarded = 'discarded';

    public function label(): string
    {
        return __('enums.review_comment_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'info',
            self::Stale => 'warning',
            self::Sent => 'success',
            self::Discarded => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Stale], true);
    }
}
