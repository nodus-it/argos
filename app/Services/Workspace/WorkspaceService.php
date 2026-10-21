<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceStatus;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeleted;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Entry point for workspace lifecycle operations. Docker work happens in the
 * queued jobs (see WorkspaceProvisioner); this service validates, records the
 * intent and dispatches.
 */
class WorkspaceService extends EntityService
{
    /** Activity timestamps are written at most this often (seconds). */
    private const TOUCH_THROTTLE = 60;

    public function __construct(
        private readonly WorkspaceMemberService $members,
        private readonly WorkspaceTeardown $teardown,
    ) {}

    protected function model(): string
    {
        return Workspace::class;
    }

    /**
     * Create a workspace (only Argos admins may). The creator becomes its
     * first admin; the container starts when someone opens it or a task
     * needs it.
     *
     * @param  array<string, mixed>  $attributes  repository, environment and settings (Workspace fillable)
     */
    public function createWorkspace(User $creator, array $attributes): Workspace
    {
        Gate::forUser($creator)->authorize('create', Workspace::class);

        return $this->register($creator, $attributes);
    }

    /**
     * createWorkspace() without the permission check — for seeders and
     * system setup that act outside a person's request.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(User $creator, array $attributes): Workspace
    {
        $workspace = DB::transaction(function () use ($creator, $attributes): Workspace {
            $workspace = new Workspace;
            $workspace->fill(Arr::except($attributes, ['slug', 'status', 'daemon_token', 'container_name', 'created_by']));
            $workspace->forceFill([
                'slug' => Workspace::generateSlug((string) ($attributes['name'] ?? 'workspace')),
                'created_by' => $creator->id,
                'status' => WorkspaceStatus::Stopped,
                'daemon_token' => Str::random(64),
            ])->save();

            $this->members->addAdmin($workspace, $creator);

            return $workspace;
        });

        $this->emit(new WorkspaceCreated($workspace));

        return $workspace;
    }

    /**
     * Change settings (admins of the workspace).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateSettings(Workspace $workspace, User $by, array $attributes): Workspace
    {
        Gate::forUser($by)->authorize('update', $workspace);
        $workspace->fill(Arr::except($attributes, ['slug', 'status', 'daemon_token', 'container_name', 'created_by']))->save();

        return $workspace;
    }

    /** Start a stopped or failed workspace again. */
    public function start(Workspace $workspace): void
    {
        if (in_array($workspace->status, [WorkspaceStatus::Running, WorkspaceStatus::Starting, WorkspaceStatus::Provisioning], true)) {
            return;
        }

        $this->ensureCapacity($workspace);
        $workspace->forceFill(['status' => WorkspaceStatus::Starting, 'status_message' => null])->save();
        ProvisionWorkspaceJob::dispatch($workspace->id);
    }

    public function stop(Workspace $workspace): void
    {
        if (! in_array($workspace->status, [WorkspaceStatus::Running, WorkspaceStatus::Failed], true)) {
            return;
        }

        $workspace->forceFill(['status' => WorkspaceStatus::Stopping])->save();
        StopWorkspaceJob::dispatch($workspace->id);
    }

    /** Purge every Docker resource, then soft-delete the row. */
    public function deleteWorkspace(Workspace $workspace): void
    {
        $this->teardown->purge($workspace->slug, $workspace->tasks()->pluck('slug')->all());
        app(TaskAttachmentService::class)->purgeWorkspace($workspace->id);
        $workspace->delete();
        $this->emit(new WorkspaceDeleted($workspace));
    }

    /** Record activity (keeps the workspace from being idle-stopped). */
    public function touch(Workspace $workspace): void
    {
        if ($workspace->last_activity_at !== null && $workspace->last_activity_at->diffInSeconds(now()) < self::TOUCH_THROTTLE) {
            return;
        }

        Workspace::query()->whereKey($workspace->getKey())->update(['last_activity_at' => now()]);
        $workspace->last_activity_at = now();
    }

    /**
     * Refuse to start beyond ARGOS_WORKSPACE_MAX_ACTIVE. Running workspaces
     * are never evicted — someone's live work would vanish.
     */
    public function ensureCapacity(?Workspace $except = null): void
    {
        $max = (int) config('argos.workspaces.max_active', 5);
        if ($max <= 0) {
            return;
        }

        $active = Workspace::query()
            ->whereIn('status', [WorkspaceStatus::Provisioning->value, WorkspaceStatus::Starting->value, WorkspaceStatus::Running->value])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->count();

        if ($active >= $max) {
            throw WorkspaceException::translated('workspaces.errors.capacity', ['max' => $max]);
        }
    }
}
