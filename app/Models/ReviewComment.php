<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewCommentStatus;
use Database\Factories\ReviewCommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line comment collected in a task's review (see ReviewCommentService).
 *
 * @property string $id
 * @property string $task_id
 * @property int $user_id
 * @property string $path
 * @property string $side new|old
 * @property int $line_start
 * @property int $line_end
 * @property string $anchor_hash
 * @property string $snippet
 * @property string $body
 * @property ReviewCommentStatus $status
 * @property bool $outdated sent anyway although Claude changed the lines since
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 * @property-read User $user
 */
class ReviewComment extends Model
{
    /** @use HasFactory<ReviewCommentFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = ['task_id', 'user_id', 'path', 'side', 'line_start', 'line_end', 'anchor_hash', 'snippet', 'body', 'status', 'outdated', 'sent_at'];

    protected function casts(): array
    {
        return [
            'status' => ReviewCommentStatus::class,
            'outdated' => 'boolean',
            'line_start' => 'integer',
            'line_end' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

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

    /** "path:12" or "path:12-14". */
    public function location(): string
    {
        return $this->path.':'.$this->line_start.($this->line_end > $this->line_start ? '-'.$this->line_end : '');
    }
}
