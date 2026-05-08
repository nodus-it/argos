<?php

declare(strict_types=1);

/**
 * Argos built-in worker stacks.
 *
 * This manifest is the source of truth for the stacks that ship with
 * Argos out of the box. The `argos:sync-builtin-images` Artisan command
 * reads it and seeds `worker_stacks` rows with `is_builtin = true`.
 *
 * Paths are resolved relative to this file's directory.
 * Hashing covers the manifest entry plus the contents at `dockerfile`.
 * A change to either re-marks the row for sync-update.
 *
 * User-created stacks (`is_builtin = false`) are never touched by the
 * sync. Built-in entries removed from this file flip to `status =
 * deprecated` rather than being deleted, so RepoProfiles referencing
 * them keep their FK target.
 *
 * Agents are not in this manifest — they live entirely in code under
 * App\Workers\Agents (registered in AppServiceProvider). Adding a new
 * agent means writing the runner class + a case in App\Enums\AgentName.
 */
return [
    'stacks' => [
        [
            'name' => 'php-8.3',
            'label' => 'PHP 8.3',
            'base_image' => 'php:8.3-cli-bookworm',
            'dockerfile' => 'stacks/Dockerfile.php-8.3',
            'capabilities' => ['php', 'composer', 'node'],
            'common_tools' => ['git', 'gh', 'jq', 'curl', 'unzip', 'coreutils'],
        ],
        [
            'name' => 'php-8.4',
            'label' => 'PHP 8.4',
            'base_image' => 'php:8.4-cli-bookworm',
            'dockerfile' => 'stacks/Dockerfile.php-8.4',
            'capabilities' => ['php', 'composer', 'node'],
            'common_tools' => ['git', 'gh', 'jq', 'curl', 'unzip', 'coreutils'],
        ],
    ],
];
