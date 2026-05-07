<?php

declare(strict_types=1);

namespace Tests\External\Support;

/**
 * Generates collision-resistant ref names so that overlapping or aborted test
 * runs do not stomp on each other on the remote (and so that cleanup can find
 * exactly the artefacts a single run produced).
 */
final class RandomizedRefName
{
    public static function branch(string $purpose = 'contract'): string
    {
        return sprintf('argos-test/%s-%s', $purpose, self::suffix());
    }

    public static function pullRequestTitle(string $purpose = 'contract'): string
    {
        return sprintf('argos-test: %s [%s]', $purpose, self::suffix());
    }

    private static function suffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
