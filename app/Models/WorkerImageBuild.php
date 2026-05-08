<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentName;
use App\Enums\WorkerImageBuildStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $worker_stack_id
 * @property AgentName $agent_name
 * @property string $tag
 * @property WorkerImageBuildStatus $status
 * @property string|null $build_log
 * @property Carbon|null $built_at
 * @property int|null $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkerStack $stack
 */
class WorkerImageBuild extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'worker_stack_id',
        'agent_name',
        'tag',
        'status',
        'build_log',
        'built_at',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'agent_name' => AgentName::class,
            'status' => WorkerImageBuildStatus::class,
            'built_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkerStack, $this>
     */
    public function stack(): BelongsTo
    {
        return $this->belongsTo(WorkerStack::class, 'worker_stack_id');
    }
}
