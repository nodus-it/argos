<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A git snapshot of the workspace taken by the daemon before a turn (or on
 * request); restoring it resets the working tree (see ThreadCheckpointService).
 *
 * @property string $id
 * @property string $thread_id
 * @property int $n
 * @property string $ref
 * @property string $sha
 * @property string|null $head_sha
 * @property string|null $label
 * @property int|null $thread_event_id
 * @property Carbon|null $created_at
 * @property-read Thread $thread
 * @property-read ThreadEvent|null $threadEvent
 */
class ThreadCheckpoint extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['thread_id', 'n', 'ref', 'sha', 'head_sha', 'label', 'thread_event_id'];

    protected function casts(): array
    {
        return ['n' => 'integer', 'thread_event_id' => 'integer'];
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<ThreadEvent, $this> */
    public function threadEvent(): BelongsTo
    {
        return $this->belongsTo(ThreadEvent::class);
    }
}
