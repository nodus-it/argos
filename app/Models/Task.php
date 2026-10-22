<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\GitProvider\PullRequestDetails;
use App\Enums\AutoTaskState;
use App\Enums\PermissionMode;
use App\Enums\SessionStatus;
use App\Enums\TaskKind;
use App\Enums\TaskOrigin;
use App\Enums\TaskState;
use App\Enums\TaskStatus;
use App\Services\Demo\DemoTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One conversation with Claude about one piece of work: its own branch,
 * worktree, database, preview and pull request. It belongs to one person
 * (and runs on their subscription); invited collaborators write in it too.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $slug
 * @property TaskKind $kind
 * @property TaskOrigin $origin
 * @property int|null $owner_user_id
 * @property int|null $created_by
 * @property string|null $agent_credential_id
 * @property string|null $parent_task_id
 * @property string $title
 * @property TaskStatus $status
 * @property SessionStatus $session_status
 * @property AutoTaskState|null $auto_state
 * @property array{plan: string, ship: string, max_fix_rounds: int, max_turns: int, max_cost_usd: float, max_minutes: int}|null $auto_config
 * @property array{fix_rounds?: int, turns?: int, started_at?: string}|null $auto_counters
 * @property string|null $auto_message
 * @property PermissionMode $permission_mode
 * @property string $model
 * @property string|null $effort
 * @property string|null $claude_session_id
 * @property list<array{name: string, description: string, argument_hint: string}>|null $commands
 * @property int $last_seq
 * @property string $cost_usd
 * @property bool $session_open
 * @property Carbon|null $queued_at
 * @property string|null $branch
 * @property string $base_branch
 * @property string|null $base_commit
 * @property string|null $worktree_path
 * @property string|null $db_name
 * @property string|null $pr_url
 * @property int|null $pr_number
 * @property string|null $pr_state
 * @property array{state?: string, url?: string|null}|null $pr_checks
 * @property string|null $pr_review_state
 * @property bool $pr_conflicts
 * @property Carbon|null $pr_synced_at
 * @property bool $auto_merge
 * @property Carbon|null $shipped_at
 * @property array{binding_id?: string, provider: string, key?: string, number: string, owner?: string, project?: string, title: string, url: string, body?: string}|null $external_ref
 * @property string|null $restore_note
 * @property Carbon|null $done_at
 * @property Carbon|null $cleaned_at
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $owner
 * @property-read User|null $creator
 * @property-read AgentCredential|null $agentCredential
 * @property-read Task|null $parent
 * @property-read Collection<int, TaskMember> $members
 * @property-read Collection<int, User> $collaborators
 */
class Task extends Model implements DemoTarget
{
    use HasFactory;
    use HasUlids;

    /** Upper bound of a task slug (worktree folder, database and preview names). */
    public const SLUG_MAX = 32;

    protected $fillable = [
        'workspace_id',
        'slug',
        'kind',
        'origin',
        'owner_user_id',
        'created_by',
        'agent_credential_id',
        'parent_task_id',
        'title',
        'status',
        'session_status',
        'permission_mode',
        'model',
        'effort',
        'claude_session_id',
        'commands',
        'last_seq',
        'cost_usd',
        'session_open',
        'queued_at',
        'branch',
        'base_branch',
        'base_commit',
        'worktree_path',
        'db_name',
        'pr_url',
        'pr_number',
        'pr_state',
        'pr_checks',
        'pr_review_state',
        'pr_conflicts',
        'pr_synced_at',
        'auto_merge',
        'shipped_at',
        'external_ref',
        'done_at',
        'cleaned_at',
        'archived_at',
    ];

    protected $attributes = [
        'kind' => 'task',
        'origin' => 'manual',
        'status' => 'active',
        'session_status' => 'closed',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'origin' => TaskOrigin::class,
            'status' => TaskStatus::class,
            'session_status' => SessionStatus::class,
            'auto_state' => AutoTaskState::class,
            'auto_config' => 'array',
            'auto_counters' => 'array',
            'permission_mode' => PermissionMode::class,
            'commands' => 'array',
            'last_seq' => 'integer',
            'cost_usd' => 'decimal:4',
            'session_open' => 'boolean',
            'queued_at' => 'datetime',
            'pr_number' => 'integer',
            'pr_checks' => 'array',
            'pr_conflicts' => 'boolean',
            'pr_synced_at' => 'datetime',
            'auto_merge' => 'boolean',
            'shipped_at' => 'datetime',
            'external_ref' => 'array',
            'done_at' => 'datetime',
            'cleaned_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Link to the task in its workspace. */
    /** The tracker binding the task's ticket came from (null without a ticket or when it was removed). */
    public function ticketBinding(): ?TaskProviderBinding
    {
        $id = $this->external_ref['binding_id'] ?? null;

        return is_string($id) ? TaskProviderBinding::query()->find($id) : null;
    }

    /** A pull request that can still change (open or draft). */
    public function hasOpenPullRequest(): bool
    {
        return $this->pr_number !== null && PullRequestDetails::isOpenState($this->pr_state);
    }

    public function url(): string
    {
        return $this->workspace->url($this);
    }

    /** What a person sees: status, session activity and archive combined. */
    public function state(): TaskState
    {
        if ($this->archived_at !== null) {
            return TaskState::Archived;
        }

        return match ($this->status) {
            TaskStatus::Preparing => TaskState::Preparing,
            TaskStatus::Done => TaskState::Done,
            TaskStatus::Cancelled => TaskState::Cancelled,
            TaskStatus::PrOpen, TaskStatus::Active => match (true) {
                $this->session_status === SessionStatus::WaitingInput,
                $this->auto_state === AutoTaskState::AwaitingPlanApproval => TaskState::Waiting,
                in_array($this->session_status, [SessionStatus::Running, SessionStatus::Starting], true),
                $this->auto_state?->isRunning() === true => TaskState::Running,
                $this->status === TaskStatus::PrOpen => TaskState::PrOpen,
                default => TaskState::Ready,
            },
        };
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }

    /** Owner or invited collaborator. */
    public function involves(User $user): bool
    {
        if ($this->isOwnedBy($user)) {
            return true;
        }

        return $this->relationLoaded('members')
            ? $this->members->contains('user_id', $user->id)
            : $this->members()->where('user_id', $user->id)->exists();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<AgentCredential, $this> */
    public function agentCredential(): BelongsTo
    {
        return $this->belongsTo(AgentCredential::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    /** @return HasMany<TaskMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(TaskMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_members')->withPivot('invited_by')->withTimestamps();
    }

    /** @return HasMany<TaskEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    /** @return HasMany<TaskApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(TaskApproval::class);
    }

    /** @return HasMany<Demo, $this> */
    public function demos(): HasMany
    {
        return $this->hasMany(Demo::class);
    }

    public function currentDemo(): ?Demo
    {
        return $this->demos()->latest()->first();
    }

    /**
     * Preview host label: `demo-<workspace>-<task>`, shortened so it stays a
     * valid DNS label.
     */
    public function demoSlug(): string
    {
        return self::demoSlugFor($this->workspace->slug, $this->slug);
    }

    /** Preview slug for a workspace and task slug; works for orphans. */
    public static function demoSlugFor(string $workspaceSlug, string $taskSlug): string
    {
        return rtrim(substr('demo-'.$workspaceSlug.'-'.$taskSlug, 0, 63), '-');
    }

    public function workspaceVolumeName(): string
    {
        return $this->workspace->volumeName();
    }

    /** The task's worktree on the workspace volume. */
    public function workspaceSubpath(): ?string
    {
        return 'tasks/'.$this->slug;
    }

    public function demoWorkspace(): Workspace
    {
        return $this->workspace;
    }

    /** The agent user of the worker image. */
    public function demoExecUser(): ?string
    {
        return '1000:1000';
    }
}
