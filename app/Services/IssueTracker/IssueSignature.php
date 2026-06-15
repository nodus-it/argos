<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\DTO\ExternalIssue;

/**
 * Computes a stable content hash for a normalized issue, used to detect
 * whether an already-linked issue changed between polls.
 */
class IssueSignature
{
    public function for(ExternalIssue $issue): string
    {
        return hash('sha256', serialize([
            $issue->title,
            $issue->body,
            $issue->state,
            $issue->labels,
        ]));
    }
}
