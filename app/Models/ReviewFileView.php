<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file a person marked "seen" in the review of a task, with the hash of
 * its changes at that moment (seen only while the hash still matches).
 *
 * @property string $id
 * @property string $task_id
 * @property int $user_id
 * @property string $path
 * @property string $path_hash
 * @property string $file_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReviewFileView extends Model
{
    use HasUlids;

    protected $fillable = ['task_id', 'user_id', 'path', 'path_hash', 'file_hash'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
