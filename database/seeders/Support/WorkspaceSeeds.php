<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;

/**
 * Idempotent workspace seeding: a new workspace goes through
 * WorkspaceService (slug, container identity, the admin as first member);
 * re-seeding updates the existing one in place.
 */
final class WorkspaceSeeds
{
    /**
     * @param  array<string, mixed>  $values
     */
    public static function upsert(User $admin, string $name, array $values): Workspace
    {
        $workspace = Workspace::query()->where('name', $name)->first();
        if ($workspace === null) {
            return app(WorkspaceService::class)->register($admin, ['name' => $name, ...$values]);
        }

        $workspace->fill($values)->save();

        return $workspace;
    }
}
