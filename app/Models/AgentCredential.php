<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCredentialStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $worker_agent_id
 * @property string $name
 * @property array<string, mixed> $credentials
 * @property AgentCredentialStatus $status
 * @property Carbon|null $last_validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkerAgent $agent
 * @property-read Collection<int, Task> $tasks
 */
class AgentCredential extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'worker_agent_id',
        'name',
        'credentials',
        'status',
        'last_validated_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'status' => AgentCredentialStatus::class,
            'last_validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkerAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(WorkerAgent::class, 'worker_agent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
