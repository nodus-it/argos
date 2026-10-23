<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person invited to collaborate on a task: they write, approve, review and
 * ship in it; the task stays its owner's.
 *
 * @property int $id
 * @property string $task_id
 * @property int $user_id
 * @property int|null $invited_by
 * @property-read Task $task
 * @property-read User $user
 */
class TaskMember extends Model
{
    protected $fillable = ['task_id', 'user_id', 'invited_by'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
