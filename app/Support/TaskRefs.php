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
        $ref = $task->external_ref;
        if ($ref === null) {
            return null;
        }

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
