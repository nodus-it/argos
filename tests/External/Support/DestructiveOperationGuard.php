<?php

declare(strict_types=1);

namespace Tests\External\Support;

use RuntimeException;

/**
 * Defense-in-depth: verifies that the configured test repo owner/name match
 * what the calling test is about to mutate. Catches the catastrophic case
 * where someone hands in production credentials by mistake — since this
 * suite issues real HTTP writes (createPullRequest, push, branch delete)
 * an unscoped token would otherwise be free to scribble across any repo
 * the token can see.
 */
final class DestructiveOperationGuard
{
    /**
     * Called from setUp. Verifies the config itself looks plausibly scoped.
     * Per-call repo matching happens in assertOperatesOn().
     */
    public static function assertScopedTo(ProviderTestConfig $config): void
    {
        if ($config->testRepoOwner === '' || $config->testRepo === '') {
            throw new RuntimeException(
                "Provider {$config->providerKey}: testRepoOwner / testRepo nicht gesetzt — Suite verweigert den Start."
            );
        }
    }

    /**
     * Called from each test before issuing a mutating call. Confirms that the
     * targeted owner/repo equals the configured test repo — protects against
     * a logic bug that would otherwise mutate something else.
     */
    public static function assertOperatesOn(ProviderTestConfig $config, string $owner, string $repo): void
    {
        if ($owner !== $config->testRepoOwner || $repo !== $config->testRepo) {
            throw new RuntimeException(
                "Mutation gegen {$owner}/{$repo} blockiert — erwartet war {$config->testRepoOwner}/{$config->testRepo}."
            );
        }
    }
}
