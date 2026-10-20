<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThreadEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One persisted entry of a thread's history (append-only).
 *
 * @property int $id
 * @property string $thread_id
 * @property ThreadEventType $type
 * @property int|null $author_user_id
 * @property int|null $daemon_seq
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 * @property-read User|null $author
 */
class ThreadEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['thread_id', 'type', 'author_user_id', 'daemon_seq', 'payload'];

    protected function casts(): array
    {
        return [
            'type' => ThreadEventType::class,
            'daemon_seq' => 'integer',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
