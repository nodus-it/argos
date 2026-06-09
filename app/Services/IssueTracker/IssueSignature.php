<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

/**
 * Computes a stable content hash for a raw provider issue, used to detect
 * whether an already-linked issue changed between polls.
 */
class IssueSignature
{
    /**
     * @param  array<string, mixed>  $issue
     */
    public function for(array $issue): string
    {
        return hash('sha256', serialize([
            $issue['title'] ?? '',
            $issue['body'] ?? $issue['description'] ?? '',
            $issue['state'] ?? $issue['status'] ?? '',
            $issue['labels'] ?? [],
        ]));
    }
}
