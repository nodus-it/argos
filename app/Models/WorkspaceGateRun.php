<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GateRunStatus;
use Database\Factories\WorkspaceGateRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One quality-gate run of a task (see WorkspaceGateService).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $task_id
 * @property string|null $base_commit
 * @property int|null $triggered_by
 * @property GateRunStatus $status
 * @property array<string, string>|null $gates
 * @property array<string, string>|null $logs
 * @property string|null $baseline
 * @property string|null $message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Workspace $workspace
 * @property-read Task $task
 * @property-read User|null $triggeredBy
 */
class WorkspaceGateRun extends Model
{
    /** @use HasFactory<WorkspaceGateRunFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['workspace_id', 'task_id', 'base_commit', 'triggered_by', 'status', 'gates', 'logs', 'baseline', 'message', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'status' => GateRunStatus::class,
            'gates' => 'array',
            'logs' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Gates that actually ran (not skipped).
     *
     * @return array<string, string>
     */
    public function ranGates(): array
    {
        return array_filter($this->gates ?? [], fn (string $result): bool => $result !== 'skip');
    }

    /** @return list<string> */
    public function failedGates(): array
    {
        return array_keys(array_filter($this->gates ?? [], fn (string $result): bool => $result === 'fail'));
    }

    public function passedCount(): int
    {
        return count(array_filter($this->gates ?? [], fn (string $result): bool => $result === 'pass'));
    }
}
