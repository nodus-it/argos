<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TaskProviderKind;
use App\Models\Task;

/**
 * Short labels for what a task refers to — wherever a task is listed the
 * ticket must be recognisable at a glance ("GitLab #42", "ENG-118").
 */
final class TaskRefs
{
    public static function ticketLabel(Task $task): ?string
    {
        return $task->external_ref !== null ? self::label($task->external_ref) : null;
    }

    /**
     * The label for a stored ticket reference ("ENG-118", "GitHub #12").
     *
     * @param  array<string, mixed>  $ref
     */
    public static function label(array $ref): string
    {
        $kind = TaskProviderKind::tryFrom((string) ($ref['provider'] ?? ''));
        $key = (string) ($ref['key'] ?? '');

        return match (true) {
            $kind === TaskProviderKind::Custom => $key !== '' ? $key : '#'.$ref['number'],
            $kind === TaskProviderKind::Linear && $key !== '' && ! str_starts_with($key, 'linear-') => $key,
            $kind !== null => $kind->label().' #'.$ref['number'],
            default => $key !== '' ? $key : '#'.$ref['number'],
        };
    }
}
