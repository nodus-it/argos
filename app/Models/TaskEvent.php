<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One persisted entry of a task's history (append-only).
 *
 * @property int $id
 * @property string $task_id
 * @property TaskEventType $type
 * @property int|null $author_user_id
 * @property int|null $daemon_seq
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 * @property-read User|null $author
 */
class TaskEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'type', 'author_user_id', 'daemon_seq', 'payload'];

    protected function casts(): array
    {
        return [
            'type' => TaskEventType::class,
            'daemon_seq' => 'integer',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
