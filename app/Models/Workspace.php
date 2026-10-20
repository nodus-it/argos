<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DemoAccessMode;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Services\Demo\DemoTarget;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A long-lived, interactive place to work on one branch of a project: one
 * container (daemon + editor), its volumes and threads.
 *
 * @property string $id
 * @property string $slug
 * @property string $repo_profile_id
 * @property int|null $created_by
 * @property string $name
 * @property string $base_branch
 * @property string $branch
 * @property WorkspaceStatus $status
 * @property string|null $status_message
 * @property string|null $container_name
 * @property string $daemon_token
 * @property Carbon|null $last_activity_at
 * @property string|null $pr_url
 * @property int|null $pr_number
 * @property string|null $pr_state
 * @property Carbon|null $pr_synced_at
 * @property Carbon|null $shipped_at
 * @property array{binding_id: string, provider: string, owner: string, project: string, number: string, title: string, url: string, body: string}|null $external_issue
 * @property string|null $gate_baseline
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read RepoProfile $repoProfile
 * @property-read User|null $creator
 */
class Workspace extends Model implements DemoTarget
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /** Upper bound so derived host and container names stay short. */
    public const SLUG_MAX = 40;

    protected $fillable = [
        'slug',
        'repo_profile_id',
        'created_by',
        'name',
        'base_branch',
        'branch',
        'status',
        'status_message',
        'container_name',
        'daemon_token',
        'last_activity_at',
        'pr_url',
        'pr_number',
        'pr_state',
        'pr_synced_at',
        'shipped_at',
        'external_issue',
        'gate_baseline',
    ];

    protected $hidden = ['daemon_token'];

    protected function casts(): array
    {
        return [
            'status' => WorkspaceStatus::class,
            'daemon_token' => 'encrypted',
            'last_activity_at' => 'datetime',
            'pr_number' => 'integer',
            'pr_synced_at' => 'datetime',
            'shipped_at' => 'datetime',
            'external_issue' => 'array',
        ];
    }

    /** Link to the workspace page, optionally opened on a thread. */
    public function url(?Thread $thread = null): string
    {
        return route('filament.workspaces.resources.workspaces.view', ['record' => $this->id] + ($thread !== null ? ['thread' => $thread->id] : []));
    }

    /** @return BelongsTo<RepoProfile, $this> */
    public function repoProfile(): BelongsTo
    {
        return $this->belongsTo(RepoProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        $member = $this->relationLoaded('members')
            ? $this->members->firstWhere('user_id', $user->id)
            : $this->members()->where('user_id', $user->id)->first();

        return $member?->role;
    }

    /** Docker volume holding the repository checkout. */
    public function volumeName(): string
    {
        return 'ws_'.$this->slug;
    }

    /** Docker volume holding the per-agent Claude config/session dirs. */
    public function agentsVolumeName(): string
    {
        return 'ws_'.$this->slug.'_agents';
    }

    public function containerName(): string
    {
        return 'argos-ws-'.$this->slug;
    }

    /**
     * Key for the workspace's live demo. Prefixed so it can never collide
     * with a task demo (`demo-<task-slug>`).
     */
    public function demoKey(): string
    {
        return 'ws-'.$this->slug;
    }

    public function demoSlug(): string
    {
        return 'demo-'.$this->demoKey();
    }

    public function workspaceVolumeName(): string
    {
        return $this->volumeName();
    }

    public function demoRepoProfile(): ?RepoProfile
    {
        return $this->repoProfile;
    }

    /** Workspace previews are members-only, like the editor. */
    public function demoAccessMode(): DemoAccessMode
    {
        return DemoAccessMode::Workspace;
    }

    public function demoBasicPassword(): ?string
    {
        return null;
    }

    /** The agent user of the worker image (see PhaseRunner / prepareVolumes). */
    public function demoExecUser(): ?string
    {
        return '1000:1000';
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

    /** DNS-/docker-safe slug: lowercase [a-z0-9-], no leading/trailing dash. */
    public static function slugify(string $value): string
    {
        $slug = Str::of(Str::ascii($value, 'de'))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();

        return rtrim(substr($slug, 0, self::SLUG_MAX - 6), '-');
    }

    /**
     * Unique slug for a new workspace. Also checked against task slugs: the
     * workspace demo key `ws-<slug>` shares the demo namespace with tasks.
     */
    public static function generateSlug(string $name): string
    {
        $base = self::slugify($name);
        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 2;
        while (self::withTrashed()->where('slug', $slug)->exists() || Task::query()->where('slug', 'ws-'.$slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
