<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DemoStatus;
use Database\Factories\DemoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ephemeral live-demo deployment for a task — one per task, replaced on the
 * next implement run. Orchestrated by the manager after implement completes
 * (see live-demo-concept.md).
 *
 * @property string $id
 * @property string $task_id
 * @property DemoStatus $status
 * @property string|null $url
 * @property string|null $compose_project
 * @property Carbon|null $ttl_until
 * @property string|null $build_log
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 */
class Demo extends Model
{
    /** @use HasFactory<DemoFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'task_id',
        'status',
        'url',
        'compose_project',
        'ttl_until',
        'build_log',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemoStatus::class,
            'ttl_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
