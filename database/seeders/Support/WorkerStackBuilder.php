<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\WorkerImageEntityStatus;
use App\Models\User;
use App\Models\WorkerStack;

/**
 * Seeds the worker-stack variants Full-Demo needs: the built-in stack flagged
 * with an available update, plus a user-created BYOI stack — so the worker-stack
 * list shows both a built-in "update available" row and a custom row.
 *
 * The built-in stack itself is created by BuiltinSync on MigrationsEnded; this
 * builder only flips its has_update flag (null-guarded: under unit tests
 * BuiltinSync is skipped, so the row may not exist).
 */
final class WorkerStackBuilder
{
    /** Flag the existing built-in stack as having an update available. */
    public function flagBuiltinHasUpdate(): ?WorkerStack
    {
        $builtin = WorkerStack::where('is_builtin', true)->first();

        $builtin?->forceFill(['has_update' => true])->save();

        return $builtin;
    }

    /** A user-created BYOI stack. Idempotent on the unique name. */
    public function customByoiStack(User $owner): WorkerStack
    {
        return WorkerStack::updateOrCreate(
            ['name' => 'byoi-node-playwright'],
            [
                'label' => 'Node + Playwright (BYOI)',
                'is_builtin' => false,
                'base_image' => 'mcr.microsoft.com/playwright:v1.49.0-noble',
                'dockerfile_body' => "FROM mcr.microsoft.com/playwright:v1.49.0-noble\nRUN apt-get update && apt-get install -y git jq curl\n",
                'common_tools' => ['git', 'jq', 'curl'],
                'capabilities' => ['node', 'playwright'],
                'status' => WorkerImageEntityStatus::Active->value,
                'has_update' => false,
                'created_by_user_id' => $owner->id,
            ],
        );
    }
}
