<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceStatus;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeleted;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntityService;
use Illuminate\Support\Facades\DB;
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

    public function createWorkspace(User $creator, RepoProfile $profile, string $name, ?string $baseBranch = null): Workspace
    {
        $this->ensureCapacity();

        $workspace = DB::transaction(function () use ($creator, $profile, $name, $baseBranch): Workspace {
            $slug = Workspace::generateSlug($name);

            $workspace = new Workspace;
            $workspace->forceFill([
                'slug' => $slug,
                'repo_profile_id' => $profile->id,
                'created_by' => $creator->id,
                'name' => $name,
                'base_branch' => $baseBranch !== null && $baseBranch !== '' ? $baseBranch : $profile->default_branch,
                'branch' => 'ws/'.$slug,
                'status' => WorkspaceStatus::Provisioning,
                'daemon_token' => Str::random(64),
            ])->save();

            $this->members->addOwner($workspace, $creator);

            return $workspace;
        });

        $this->emit(new WorkspaceCreated($workspace));
        ProvisionWorkspaceJob::dispatch($workspace->id);

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
        $this->teardown->purge($workspace->slug);
        app(ThreadAttachmentService::class)->purgeWorkspace($workspace->id);
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
