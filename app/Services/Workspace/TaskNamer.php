<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Names a new task's slug (worktree folder, database, preview) and branch.
 *
 * Branches: with a ticket `<key>-<title>` (`gitlab-42-login-seite`,
 * `eng-118-login-seite`), without one `argos/<title>`. A workspace can set its
 * own pattern with the placeholders `{ticket}` (the ticket key, or "argos"
 * without one) and `{title}`. A taken name gets a numeric suffix.
 */
class TaskNamer
{
    public const BRANCH_MAX = 60;

    /** Unique slug within the workspace. */
    public function slug(Workspace $workspace, string $title): string
    {
        $base = Workspace::slugify($title, Task::SLUG_MAX - 4);
        if ($base === '') {
            $base = 'task';
        }

        return $this->unique($base, fn (string $slug): bool => Task::query()->where('workspace_id', $workspace->id)->where('slug', $slug)->exists());
    }

    /** Unique branch name within the workspace. */
    public function branch(Workspace $workspace, string $title, ?string $ticketKey = null): string
    {
        $ticket = $ticketKey !== null && $ticketKey !== '' ? Str::slug($ticketKey) : null;
        $pattern = $workspace->branch_pattern !== null && $workspace->branch_pattern !== ''
            ? $workspace->branch_pattern
            : ($ticket !== null ? '{ticket}-{title}' : 'argos/{title}');

        $branch = strtr($pattern, ['{ticket}' => $ticket ?? 'argos', '{title}' => Str::slug(Str::ascii($title, 'de'))]);
        $branch = trim((string) preg_replace('#[^A-Za-z0-9._/-]+#', '-', $branch), '-/');
        $branch = rtrim(mb_substr($branch !== '' ? $branch : 'argos/task', 0, self::BRANCH_MAX - 4), '-/');

        return $this->unique($branch, fn (string $name): bool => Task::query()->where('workspace_id', $workspace->id)->where('branch', $name)->exists());
    }

    /** @param  callable(string): bool  $taken */
    private function unique(string $base, callable $taken): string
    {
        $name = $base;
        $suffix = 2;
        while ($taken($name)) {
            $name = $base.'-'.$suffix++;
        }

        return $name;
    }
}
