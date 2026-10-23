<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use Illuminate\Database\Eloquent\Builder;
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
 * @property list<array{value: string, label: string, description: string, resolved: string|null, effort_levels?: list<string>}>|null $models
 * @property Carbon|null $models_refreshed_at
 * @property Carbon|null $usage_limited_until
 * @property-read User|null $user
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
        'models',
        'models_refreshed_at',
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
            'models' => 'array',
            'models_refreshed_at' => 'datetime',
        ];
    }

    /**
     * Owner whose subscription the agent runs on; null for shared agents.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The Claude OAuth token the workspace daemon starts sessions with ('' when missing). */
    public function oauthToken(): string
    {
        return (string) ($this->credentials['token'] ?? '');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
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
     * Agents a user may run tasks on: their own plus shared ones.
     *
     * @param  Builder<AgentCredential>  $query
     */
    public function scopeUsableBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }
}
