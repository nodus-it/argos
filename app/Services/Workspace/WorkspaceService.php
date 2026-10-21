<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceOrigin;
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

    /**
     * @param  array{binding_id: string, provider: string, owner: string, project: string, number: string, title: string, url: string, body: string}|null  $issue  from WorkspaceIssueService::load()
     */
    public function createWorkspace(User $creator, RepoProfile $profile, string $name, ?string $baseBranch = null, ?array $issue = null, WorkspaceOrigin $origin = WorkspaceOrigin::Manual): Workspace
    {
        $this->ensureCapacity();

        $workspace = DB::transaction(function () use ($creator, $profile, $name, $baseBranch, $issue, $origin): Workspace {
            $slug = Workspace::generateSlug($name);
            $branch = $issue !== null ? $this->issueBranch($issue) : 'ws/'.$slug;

            $workspace = new Workspace;
            $workspace->forceFill([
                'slug' => $slug,
                'repo_profile_id' => $profile->id,
                'created_by' => $creator->id,
                'name' => $name,
                'base_branch' => $baseBranch !== null && $baseBranch !== '' ? $baseBranch : $profile->default_branch,
                'branch' => $branch,
                'status' => WorkspaceStatus::Provisioning,
                'daemon_token' => Str::random(64),
                'external_issue' => $issue,
                'origin' => $origin,
            ])->save();

            $this->members->addOwner($workspace, $creator);

            return $workspace;
        });

        $this->emit(new WorkspaceCreated($workspace));
        ProvisionWorkspaceJob::dispatch($workspace->id);

        return $workspace;
    }

    /**
     * `ws/<nr>-<slug>`; a second workspace for the same issue gets a suffix.
     *
     * @param  array{number: string, title: string}  $issue
     */
    private function issueBranch(array $issue): string
    {
        $branch = app(WorkspaceIssueService::class)->branchFor($issue['number'], $issue['title']);
        if (! Workspace::withTrashed()->where('branch', $branch)->exists()) {
            return $branch;
        }

        return mb_substr($branch, 0, WorkspaceIssueService::BRANCH_MAX - 5).'-'.Str::lower(Str::random(4));
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
