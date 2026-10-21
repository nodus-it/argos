<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hands out agent session slots. An open task session occupies one slot of
 * its agent; an agent never runs more sessions than its
 * `max_concurrent_sessions`, which protects the owner's subscription limits.
 */
class AgentPool
{
    /** Fallback when a limit message carries no reset time. */
    private const DEFAULT_LIMIT_MINUTES = 60;

    /**
     * Agents the user may start a task on: own and shared ones whose agent
     * the workspace daemon supports, active and not usage-limited right now.
     *
     * @return Collection<int, AgentCredential>
     */
    public function usableAgentsFor(User $user): Collection
    {
        return AgentCredential::query()
            ->usableBy($user)
            ->whereIn('agent_name', array_map(
                fn (AgentName $name): string => $name->value,
                array_filter(AgentName::cases(), fn (AgentName $name): bool => $name->supportsWorkspaces()),
            ))
            ->where('status', AgentCredentialStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('usage_limited_until')->orWhere('usage_limited_until', '<=', now()))
            ->orderByRaw('user_id is null')
            ->orderBy('name')
            ->get();
    }

    public function activeSessions(AgentCredential $agent): int
    {
        return Task::query()
            ->where('agent_credential_id', $agent->id)
            ->where('session_open', true)
            ->count();
    }

    /**
     * Occupy a slot for the task's agent. Idempotent for a task that
     * already holds its slot.
     *
     * @throws AgentBusyException when the agent is missing, limited or full
     */
    public function acquire(Task $task): void
    {
        DB::transaction(function () use ($task): void {
            /** @var Task $locked */
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->session_open) {
                return;
            }

            $agent = $locked->agent_credential_id === null
                ? null
                : AgentCredential::query()->whereKey($locked->agent_credential_id)->lockForUpdate()->first();

            if ($agent === null || $agent->status !== AgentCredentialStatus::Active) {
                throw AgentBusyException::translated('workspaces.errors.agent_unavailable');
            }
            if ($agent->isUsageLimited()) {
                throw AgentBusyException::translated('workspaces.errors.agent_usage_limited', [
                    'agent' => $agent->name,
                    'time' => $agent->usage_limited_until?->toDateTimeString() ?? '',
                ]);
            }
            if ($this->activeSessions($agent) >= max(1, $agent->max_concurrent_sessions)) {
                throw AgentBusyException::translated('workspaces.errors.agent_busy', [
                    'agent' => $agent->name,
                    'max' => max(1, $agent->max_concurrent_sessions),
                ]);
            }

            $locked->forceFill(['session_open' => true])->save();
        });

        $task->session_open = true;
    }

    public function release(Task $task): void
    {
        Task::query()->whereKey($task->getKey())->update(['session_open' => false]);
        $task->session_open = false;
    }

    public function markUsageLimited(AgentCredential $agent, ?CarbonImmutable $resetsAt): void
    {
        $until = $resetsAt !== null && $resetsAt->isFuture() ? $resetsAt : now()->addMinutes(self::DEFAULT_LIMIT_MINUTES);
        $agent->forceFill(['usage_limited_until' => $until])->save();
    }
}
