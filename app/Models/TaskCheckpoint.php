<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A git snapshot of the workspace taken by the daemon before a turn (or on
 * request); restoring it resets the working tree (see TaskCheckpointService).
 *
 * @property string $id
 * @property string $task_id
 * @property int $n
 * @property string $ref
 * @property string $sha
 * @property string|null $head_sha
 * @property string|null $label
 * @property int|null $task_event_id
 * @property Carbon|null $created_at
 * @property-read Task $task
 * @property-read TaskEvent|null $taskEvent
 */
class TaskCheckpoint extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'n', 'ref', 'sha', 'head_sha', 'label', 'task_event_id'];

    protected function casts(): array
    {
        return ['n' => 'integer', 'task_event_id' => 'integer'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TaskEvent, $this> */
    public function taskEvent(): BelongsTo
    {
        return $this->belongsTo(TaskEvent::class);
    }
}
