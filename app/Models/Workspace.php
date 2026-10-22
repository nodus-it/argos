<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\BackingService;
use App\Enums\GitProvider;
use App\Enums\WorkerSource;
use App\Enums\WorkspaceSetupStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Services\OAuth\TokenRefresher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * A project: one repository with its environment (stack, services, secrets,
 * preview), its people and settings, and one long-lived container (daemon +
 * editor + sidecars) in which all of its tasks work.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property int|null $created_by
 * @property string $repo_url
 * @property GitProvider $platform
 * @property AuthMethod $auth_method
 * @property string|null $token
 * @property int|null $connected_account_id
 * @property string $default_branch
 * @property string|null $branch_pattern
 * @property WorkerSource $worker_source
 * @property string|null $worker_stack_id
 * @property array<int, array{host: string, username?: string|null, token: string}>|null $composer_registries
 * @property array<int, array{name: string, value: string}>|null $worker_env
 * @property array<int, string>|null $worker_services
 * @property array<string, array<string, string>>|null $worker_service_config
 * @property bool $live_demo_enabled
 * @property WorkspaceSetupStatus|null $setup_status
 * @property string|null $setup_branch
 * @property list<array{step: string, ok: bool, log: string}>|null $setup_check
 * @property int $max_sessions
 * @property int $max_previews
 * @property string|null $cpu_limit
 * @property int|null $memory_limit_mb
 * @property int $cleanup_after_days
 * @property array<string, mixed>|null $auto_settings
 * @property int|null $automation_user_id
 * @property WorkspaceStatus $status
 * @property string|null $status_message
 * @property string|null $container_name
 * @property string $daemon_token
 * @property Carbon|null $last_activity_at
 * @property array<string, string>|null $gate_baselines
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creator
 * @property-read User|null $automationUser
 * @property-read ConnectedAccount|null $connectedAccount
 * @property-read WorkerStack|null $workerStack
 * @property-read Collection<int, WorkspaceMember> $members
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, TaskProviderBinding> $taskProviderBindings
 */
class Workspace extends Model implements HasApiTokensContract
{
    // Workspace-bound Sanctum API tokens: one token = one workspace, fine-scoped
    // (e.g. CI). $workspace->createToken('ci', ['tasks:write']).
    use HasApiTokens;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /** Upper bound so derived host and container names stay short. */
    public const SLUG_MAX = 40;

    protected $fillable = [
        'slug',
        'name',
        'created_by',
        'repo_url',
        'platform',
        'auth_method',
        'token',
        'connected_account_id',
        'default_branch',
        'branch_pattern',
        'worker_source',
        'worker_stack_id',
        'composer_registries',
        'worker_env',
        'worker_services',
        'worker_service_config',
        'live_demo_enabled',
        'setup_status',
        'setup_branch',
        'setup_check',
        'max_sessions',
        'max_previews',
        'cpu_limit',
        'memory_limit_mb',
        'cleanup_after_days',
        'auto_settings',
        'automation_user_id',
        'status',
        'status_message',
        'container_name',
        'daemon_token',
        'last_activity_at',
        'gate_baselines',
    ];

    protected $hidden = ['daemon_token', 'token'];

    protected function casts(): array
    {
        return [
            'platform' => GitProvider::class,
            'auth_method' => AuthMethod::class,
            'token' => 'encrypted',
            'worker_source' => WorkerSource::class,
            'setup_status' => WorkspaceSetupStatus::class,
            'setup_check' => 'array',
            'composer_registries' => 'encrypted:array',
            'worker_env' => 'encrypted:array',
            'worker_services' => 'array',
            'worker_service_config' => 'array',
            'live_demo_enabled' => 'boolean',
            'max_sessions' => 'integer',
            'max_previews' => 'integer',
            'memory_limit_mb' => 'integer',
            'cleanup_after_days' => 'integer',
            'auto_settings' => 'array',
            'status' => WorkspaceStatus::class,
            'daemon_token' => 'encrypted',
            'last_activity_at' => 'datetime',
            'gate_baselines' => 'array',
        ];
    }

    /** URLs name the workspace (`/argos-playground`), not its ULID. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The slug — or the ULID that older links and API clients carry.
     *
     * @param  Builder<static>|Relation<*, *, *>  $query
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field !== null && $field !== 'slug') {
            return parent::resolveRouteBindingQuery($query, $value, $field);
        }

        return $query->where(fn ($q) => $q->where($this->qualifyColumn('slug'), (string) $value)->orWhere($this->qualifyColumn('id'), (string) $value));
    }

    /** Link to the workspace page, optionally opened on a task. */
    public function url(?Task $task = null): string
    {
        return route('filament.workspaces.resources.workspaces.view', ['record' => $this->slug] + ($task !== null ? ['task' => $task->slug] : []));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The person auto-tasks of workspace tokens and issue triggers act for
     * when the assignee has not agreed to run them.
     *
     * @return BelongsTo<User, $this>
     */
    public function automationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'automation_user_id');
    }

    /** @return BelongsTo<ConnectedAccount, $this> */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /** @return BelongsTo<WorkerStack, $this> */
    public function workerStack(): BelongsTo
    {
        return $this->belongsTo(WorkerStack::class);
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

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<TaskProviderBinding, $this> */
    public function taskProviderBindings(): HasMany
    {
        return $this->hasMany(TaskProviderBinding::class);
    }

    /** @return HasMany<Demo, $this> */
    public function demos(): HasMany
    {
        return $this->hasMany(Demo::class);
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        $member = $this->relationLoaded('members')
            ? $this->members->firstWhere('user_id', $user->id)
            : $this->members()->where('user_id', $user->id)->first();

        return $member?->role;
    }

    /** Docker volume holding the main clone and the task worktrees. */
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
     * The backing services this workspace opts into, as resolved enum cases
     * (unknown stored values are dropped).
     *
     * @return list<BackingService>
     */
    public function backingServices(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?BackingService => BackingService::tryFrom($value),
            $this->worker_services ?? [],
        )));
    }

    /**
     * The repository token: the PAT, or the OAuth token of the linked
     * account (refreshed when it would expire soon).
     *
     * @throws \RuntimeException when no token is configured or the refresh fails
     */
    public function resolveToken(): string
    {
        if ($this->auth_method !== AuthMethod::OAuth) {
            if ($this->token !== null && $this->token !== '') {
                return $this->token;
            }

            throw new \RuntimeException('No access token configured. Add a token to the workspace or connect an account.');
        }

        $account = $this->connectedAccount;
        if ($account === null) {
            throw new \RuntimeException('No OAuth account linked. Connect an account first.');
        }

        return app(TokenRefresher::class)->refreshIfNeeded($account)->token;
    }

    /**
     * Where the `.argos/` setup comes from: a tested but unmerged setup
     * branch, else the default branch.
     */
    public function setupRef(): string
    {
        return $this->setup_branch ?? $this->default_branch;
    }

    /**
     * The `owner/repo` path of the repository URL (GitLab owners keep their
     * subgroups), without scheme, host and `.git`.
     */
    public function getOwnerRepo(): string
    {
        $path = rtrim(parse_url($this->repo_url, PHP_URL_PATH) ?? '', '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return ltrim($path, '/');
    }

    /**
     * `getOwnerRepo()` split into owner and repository name.
     *
     * @return array{0: string, 1: string}
     */
    public function ownerAndRepo(): array
    {
        $parts = explode('/', $this->getOwnerRepo());
        $repo = (string) array_pop($parts);

        return [implode('/', $parts), $repo];
    }

    /**
     * Trim whitespace and trailing slashes: a trailing "/" would leak into
     * provider API paths (`/repos/<owner>/<repo>//pulls` → 404).
     */
    protected function repoUrl(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : rtrim(trim($value), '/'),
        );
    }

    /** DNS-/docker-safe slug: lowercase [a-z0-9-], no leading/trailing dash. */
    public static function slugify(string $value, int $max = self::SLUG_MAX - 6): string
    {
        $slug = Str::of(Str::ascii($value, 'de'))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();

        return rtrim(substr($slug, 0, $max), '-');
    }

    /**
     * The workspace's address is `/{slug}`: names the app itself uses at the
     * top level (account, docs, api, …) cannot be a workspace.
     */
    public static function isReservedSlug(string $slug): bool
    {
        return in_array($slug, config('argos.reserved_slugs', []), true) || str_starts_with($slug, 'livewire');
    }

    /** Route pattern for `{record}`: any slug that is not reserved. */
    public static function routePattern(): string
    {
        $reserved = implode('|', array_map(fn (string $s): string => preg_quote($s, '#'), config('argos.reserved_slugs', [])));

        // The pattern sits inside the whole route's regex: "end of the name" is
        // "no further slug character", not `$`.
        return '(?!(?:'.$reserved.')(?![a-z0-9-]))(?!livewire)[a-z0-9][a-z0-9-]*';
    }

    /** Unique slug for a new workspace (soft-deleted ones included). */
    public static function generateSlug(string $name): string
    {
        $base = self::slugify($name);
        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 2;
        while (self::isReservedSlug($slug) || self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
