<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GateRunStatus;
use App\Models\Task;
use App\Models\WorkspaceGateRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceGateRun>
 */
class WorkspaceGateRunFactory extends Factory
{
    protected $model = WorkspaceGateRun::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'workspace_id' => fn (array $attributes): string => Task::query()->findOrFail($attributes['task_id'])->workspace_id,
            'status' => GateRunStatus::Passed,
            'gates' => ['artisan' => 'pass', 'pint' => 'pass', 'pest' => 'pass', 'phpstan' => 'skip'],
            'logs' => [],
            'baseline' => 'ready',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
