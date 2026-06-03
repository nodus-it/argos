<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\RepoProfile;
use App\Services\GitProvider\GitServiceFactory;

/**
 * Detects whether a repo ships the Live-Demo contract (`.argos/demo.yml` +
 * `.argos/demo.compose.yml`) at its default branch — read via the provider API
 * (reuses P4's getFileContents), so no clone is needed to gate the toggle.
 */
final class DemoConfigLocator
{
    public const SETTINGS_PATH = '.argos/demo.yml';

    public const COMPOSE_PATH = '.argos/demo.compose.yml';

    public function __construct(private readonly GitServiceFactory $factory) {}

    /** Whether both demo files exist at the profile's default branch. */
    public function hasConfig(RepoProfile $profile): bool
    {
        try {
            $service = $this->factory->fromRepoProfile($profile);
            $ownerRepo = $profile->getOwnerRepo();
            $ref = $profile->default_branch;

            return $service->getFileContents($ownerRepo, self::COMPOSE_PATH, $ref) !== null
                && $service->getFileContents($ownerRepo, self::SETTINGS_PATH, $ref) !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
