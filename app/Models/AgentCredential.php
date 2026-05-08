<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property AgentName $agent_name
 * @property string $name
 * @property array<string, mixed> $credentials
 * @property AgentCredentialStatus $status
 * @property Carbon|null $last_validated_at
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
    ];

    protected function casts(): array
    {
        return [
            'agent_name' => AgentName::class,
            'credentials' => 'encrypted:array',
            'status' => AgentCredentialStatus::class,
            'last_validated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
