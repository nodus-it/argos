<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\DemoAccessMode;
use App\Models\RepoProfile;

/**
 * Something a live demo can be built for: a task (after implement) or an
 * interactive workspace. The demo mounts the target's workspace volume, so
 * what it shows is always the target's current files.
 */
interface DemoTarget
{
    /** DNS-safe key: compose project, edge alias, Traefik router and subdomain. */
    public function demoSlug(): string;

    /** Docker volume holding the checkout the demo mounts. */
    public function workspaceVolumeName(): string;

    public function demoRepoProfile(): ?RepoProfile;

    /** Effective protection of the demo route (never `Inherit`). */
    public function demoAccessMode(): DemoAccessMode;

    public function demoBasicPassword(): ?string;

    /**
     * uid:gid the contract commands run as, or null for the image default.
     * Workspaces keep editing the volume after the demo ran, so files the
     * commands create must stay owned by the agent user.
     */
    public function demoExecUser(): ?string;
}
