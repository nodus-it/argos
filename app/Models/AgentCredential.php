<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property AgentName $agent_name
 * @property string $name
 * @property array<string, mixed> $credentials
 * @property AgentCredentialStatus $status
 * @property Carbon|null $last_validated_at
 * @property int|null $user_id
 * @property int $max_concurrent_sessions
 * @property string|null $default_model
 * @property Carbon|null $usage_limited_until
 * @property-read User|null $user
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Task> $tasks
 */
class AgentCredential extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'agent_name',
        'name',
        'credentials',
        'status',
        'last_validated_at',
        'user_id',
        'max_concurrent_sessions',
        'default_model',
        'usage_limited_until',
    ];

    protected function casts(): array
    {
        return [
            'agent_name' => AgentName::class,
            'credentials' => 'encrypted:array',
            'status' => AgentCredentialStatus::class,
            'last_validated_at' => 'datetime',
            'max_concurrent_sessions' => 'integer',
            'usage_limited_until' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Task, $this>
     */
    /**
     * Owner whose subscription the agent runs on; null for shared agents.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    public function isUsageLimited(): bool
    {
        return $this->usage_limited_until !== null && $this->usage_limited_until->isFuture();
    }

    /**
     * Shared agents only (no owner) — what tasks fall back to.
     *
     * @param  Builder<AgentCredential>  $query
     */
    public function scopeShared(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * Agents a user may run threads on: their own plus shared ones.
     *
     * @param  Builder<AgentCredential>  $query
     */
    public function scopeUsableBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
