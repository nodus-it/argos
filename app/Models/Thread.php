<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PermissionMode;
use App\Enums\ThreadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation with one agent inside a workspace. Several members may
 * write in it; all prompts run on the agent owner's subscription.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $agent_credential_id
 * @property int|null $created_by
 * @property string $title
 * @property ThreadStatus $status
 * @property PermissionMode $permission_mode
 * @property string $model
 * @property string|null $claude_session_id
 * @property list<array{name: string, description: string, argument_hint: string}>|null $commands
 * @property int $last_seq
 * @property string $cost_usd
 * @property bool $session_open
 * @property Carbon|null $archived_at
 * @property string|null $restore_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Workspace $workspace
 * @property-read AgentCredential|null $agentCredential
 */
class Thread extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'agent_credential_id',
        'created_by',
        'title',
        'status',
        'permission_mode',
        'model',
        'claude_session_id',
        'commands',
        'last_seq',
        'cost_usd',
        'session_open',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ThreadStatus::class,
            'permission_mode' => PermissionMode::class,
            'commands' => 'array',
            'last_seq' => 'integer',
            'cost_usd' => 'decimal:4',
            'session_open' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AgentCredential, $this> */
    public function agentCredential(): BelongsTo
    {
        return $this->belongsTo(AgentCredential::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ThreadEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ThreadEvent::class);
    }

    /** @return HasMany<ThreadApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(ThreadApproval::class);
    }
}
