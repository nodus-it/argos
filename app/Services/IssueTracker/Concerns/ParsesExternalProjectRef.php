<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Concerns;

trait ParsesExternalProjectRef
{
    /**
     * Split a TaskProviderBinding's "owner/project" external project ref into
     * [owner, project]. Missing parts come back as empty strings.
     *
     * @return array{0: string, 1: string}
     */
    private function parseRef(string $ref): array
    {
        $parts = explode('/', $ref, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
