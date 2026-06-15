<?php

declare(strict_types=1);

namespace App\Services\Workflow;

/**
 * The Docker labels every per-run container and network carries so the
 * resources of a task's phase run can be found again after the fact — for
 * an explicit abort, for task-delete teardown, and for the orphan sweep that
 * reaps anything a hard process kill left behind. Worker containers run with
 * `--rm` and stay anonymous otherwise; these labels are the only durable
 * handle on them.
 */
final class WorkerRunLabels
{
    public const ROLE = 'argos.role';

    public const TASK = 'argos.task';

    public const PHASE = 'argos.phase';

    public const ROLE_WORKER = 'worker';

    public const ROLE_SIDECAR = 'sidecar';

    public const ROLE_NETWORK = 'network';

    /**
     * The `--label k=v` argument pairs for a `docker run` / `docker network
     * create` of the given role, task and phase.
     *
     * @return list<string>
     */
    public static function args(string $role, string $taskId, string $phase): array
    {
        return [
            '--label', self::ROLE.'='.$role,
            '--label', self::TASK.'='.$taskId,
            '--label', self::PHASE.'='.$phase,
        ];
    }
}
