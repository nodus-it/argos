<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Workspace;

/**
 * Something a live demo can be built for. Tasks are the only implementation
 * today; the interface stays as the seam. The demo mounts the target's
 * workspace volume, so what it shows is always the target's current files.
 */
interface DemoTarget
{
    /** DNS-safe key: compose project, edge alias, Traefik router and subdomain. */
    public function demoSlug(): string;

    /** Docker volume holding the checkout the demo mounts. */
    public function workspaceVolumeName(): string;

    /** Folder inside that volume to mount (null: the whole volume). */
    public function workspaceSubpath(): ?string;

    /** The workspace whose environment (contract, services, secrets) the demo uses. */
    public function demoWorkspace(): Workspace;

    /**
     * uid:gid the contract commands run as, or null for the image default.
     * Workspaces keep editing the volume after the demo ran, so files the
     * commands create must stay owned by the agent user.
     */
    public function demoExecUser(): ?string;
}
