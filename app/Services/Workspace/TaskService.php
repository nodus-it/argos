<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AgentName;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\Capability;
use App\Enums\AutoTaskState;
use App\Enums\PermissionMode;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskKind;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Jobs\Workspace\DrainSessionQueueJob;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Everything humans do in a task: open the session, write, interrupt,
 * switch mode or model, answer approvals, close. Authorization is checked
 * here (policies), so every entry point — UI, API, MCP — gets the same rules.
 */
class TaskService
{
    public function __construct(
        private readonly AgentPool $pool,
        private readonly WorkspaceDaemonClient $daemon,
        private readonly WorkspaceService $workspaces,
        private readonly TaskStreamPublisher $publisher,
        private readonly TaskNamer $namer,
    ) {}

    /**
     * Create a task owned by `$by`, running on their agent.
     *
     * @param  bool  $automated  an auto-task (AutoTaskDriver): it may run
     *                           in bypass mode — it is isolated in the container
     *                           and nobody is there to answer approvals
     * @param  array{base_branch?: string|null, external_ref?: array<string, mixed>|null, origin?: TaskOrigin, kind?: TaskKind, parent_task_id?: string|null}  $options
     */
    public function create(Workspace $workspace, User $by, AgentCredential $agent, string $title, ?string $model = null, PermissionMode $mode = PermissionMode::Default, bool $automated = false, array $options = []): Task
    {
        Gate::forUser($by)->authorize('create', [Task::class, $workspace]);
        Gate::forUser($by)->authorize('use', $agent);
        if ($agent->agent_name !== AgentName::ClaudeCode) {
            throw WorkspaceException::translated('workspaces.errors.agent_unavailable');
        }
        if (! $automated) {
            $this->assertModeAllowed($workspace, $by, $mode);
        }

        $ref = $options['external_ref'] ?? null;
        $baseBranch = $options['base_branch'] ?? null;

        $task = new Task;
        $task->forceFill([
            'workspace_id' => $workspace->id,
            'slug' => $this->namer->slug($workspace, $title),
            'kind' => $options['kind'] ?? TaskKind::Task,
            'origin' => $options['origin'] ?? TaskOrigin::Manual,
            'owner_user_id' => $by->id,
            'agent_credential_id' => $agent->id,
            'created_by' => $by->id,
            'parent_task_id' => $options['parent_task_id'] ?? null,
            'title' => $title,
            'status' => TaskStatus::Preparing,
            'session_status' => SessionStatus::Closed,
            'branch' => $this->namer->branch($workspace, $title, isset($ref['key']) ? (string) $ref['key'] : null),
            'base_branch' => $baseBranch !== null && $baseBranch !== '' ? $baseBranch : $workspace->default_branch,
            'external_ref' => $ref,
            'permission_mode' => $mode,
            'model' => $model ?? $agent->default_model ?? ModelCatalog::DEFAULT,
            'last_seq' => 0,
            'cost_usd' => 0,
            'session_open' => false,
        ])->save();

        // Worktree, database and setup commands; Claude starts after that.
        PrepareTaskJob::dispatch($task->id)->afterCommit();

        return $task;
    }

    /**
     * Start (or resume) the daemon session. Occupies one slot of the agent.
     */
    public function open(Task $task): void
    {
        $workspace = $task->workspace;
        if ($workspace->status !== WorkspaceStatus::Running) {
            throw WorkspaceException::translated('workspaces.errors.not_running');
        }
        if ($task->status === TaskStatus::Preparing) {
            throw WorkspaceException::translated('workspaces.errors.preparing');
        }

        try {
            $this->pool->acquire($task);
        } catch (SessionsFullException $e) {
            // A resting session makes room before anyone has to wait.
            if (! $this->evictIdle($task, $e->scope)) {
                throw $e;
            }
            $this->pool->acquire($task);
        }
        $agent = $task->agentCredential;
        if ($agent === null) {
            $this->pool->release($task);
            throw WorkspaceException::translated('workspaces.errors.agent_unavailable');
        }

        try {
            $this->daemon->for($workspace)->startTask($task->id, [
                'worktree' => $task->slug,
                // Nobody answers in an automatic run: file tools outside the task's folder are refused.
                'guard' => $task->auto_state?->isRunning() === true ? 'deny' : 'ask',
                'agent_id' => $agent->id,
                'oauth_token' => (string) ($agent->credentials['token'] ?? ''),
                'model' => $task->model,
                'permission_mode' => $task->permission_mode->value,
                'resume_session_id' => $task->claude_session_id,
                'seq_offset' => $task->last_seq,
                'append_system_prompt' => $this->systemPrompt($task),
            ]);
        } catch (DaemonTransportException $e) {
            $this->pool->release($task);
            $task->forceFill(['session_status' => SessionStatus::Error])->save();

            throw WorkspaceException::fromDaemon($e);
        }

        $task->forceFill(['session_status' => SessionStatus::Starting])->save();
    }

    /**
     * Post a message as `$author`. Opens the session on demand; while a turn
     * is running the daemon queues it as a follow-up. Without a free session
     * (workspace or subscription limit) the message waits in the session
     * queue and goes out once one frees up. A message the daemon refused is
     * removed again, so history only shows delivered or waiting ones.
     *
     * @param  list<array{media_type: string, data: string, path?: string}>  $images
     */
    public function send(Task $task, User $author, string $text, array $images = [], bool $automated = false): TaskEvent
    {
        Gate::forUser($author)->authorize('prompt', $task);
        if ($task->status->isFinished()) {
            throw WorkspaceException::translated('workspaces.errors.task_finished');
        }

        // A human writing into a running auto-task takes over: automation pauses.
        if (! $automated && $task->auto_state?->isRunning() === true) {
            $task->forceFill(['auto_state' => AutoTaskState::Paused, 'auto_message' => __('workspaces.auto.paused_by', ['name' => $author->name])])->save();
        }

        $queued = false;
        if (! $task->session_open) {
            try {
                $this->open($task);
            } catch (SessionsFullException) {
                $queued = true;
            }
        }

        $event = TaskEvent::query()->create([
            'task_id' => $task->id,
            'type' => TaskEventType::UserMessage,
            'author_user_id' => $author->id,
            'payload' => [
                'text' => $text,
                'author_name' => $author->name,
                'images' => array_map(fn (array $image): array => ['media_type' => $image['media_type'], 'path' => $image['path'] ?? null], $images),
            ] + ($automated ? ['auto' => true] : []) + ($queued ? ['pending' => true] : []),
        ]);

        if ($queued) {
            $this->enqueue($task, $event, $author);

            return $event;
        }

        $payload = [
            'client_msg_id' => (string) $event->id,
            'author' => ['id' => $author->id, 'name' => $author->name],
            // After a restore Claude's context is out of date; say so once.
            'text' => $task->restore_note !== null ? '['.$task->restore_note."]\n\n".$text : $text,
        ];
        if ($images !== []) {
            $payload['images'] = array_map(fn (array $image): array => ['media_type' => $image['media_type'], 'data' => $image['data']], $images);
        }

        try {
            $this->deliver($task, $payload);
        } catch (WorkspaceException $e) {
            $event->delete();

            throw $e;
        }

        if ($task->restore_note !== null) {
            $task->forceFill(['restore_note' => null])->save();
        }

        $this->publisher->publish($task->workspace_id, $task->id, [[
            'type' => 'user.message',
            'event_id' => $event->id,
            'data' => ['text' => $text, 'author_id' => $author->id, 'author_name' => $author->name],
        ]]);
        $this->workspaces->touch($task->workspace);

        return $event;
    }

    public function interrupt(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('interrupt', $task);
        if (! $task->session_open) {
            return;
        }

        $this->callDaemon(fn () => $this->daemon->for($task->workspace)->interrupt($task->id));
        $this->notice($task, 'interrupted', $by);
    }

    public function setPermissionMode(Task $task, User $by, PermissionMode $mode): void
    {
        Gate::forUser($by)->authorize('changeMode', $task);
        $this->assertModeAllowed($task->workspace, $by, $mode);

        $task->forceFill(['permission_mode' => $mode])->save();
        if ($task->session_open) {
            $this->callDaemon(fn () => $this->daemon->for($task->workspace)->setPermissionMode($task->id, $mode->value));
        }
        $this->notice($task, 'permission_mode', $by, ['mode' => $mode->value]);
    }

    /**
     * Switch the mode for an auto-task (AutoTaskDriver) — no policy or
     * owner check: the driver acts for the task's creator.
     */
    public function applyAutomatedMode(Task $task, PermissionMode $mode): void
    {
        $task->forceFill(['permission_mode' => $mode])->save();
        if ($task->session_open) {
            $this->callDaemon(fn () => $this->daemon->for($task->workspace)->setPermissionMode($task->id, $mode->value));
        }
    }

    public function setModel(Task $task, User $by, string $model): void
    {
        Gate::forUser($by)->authorize('changeMode', $task);

        $task->forceFill(['model' => $model])->save();
        if ($task->session_open) {
            $this->callDaemon(fn () => $this->daemon->for($task->workspace)->setModel($task->id, $model));
        }
        $this->notice($task, 'model', $by, ['model' => $model]);
    }

    /**
     * Answer a pending approval. The first decision wins; returns false when
     * someone else already decided.
     *
     * @param  array<string, mixed>|null  $updatedInput
     */
    public function resolveApproval(TaskApproval $approval, User $by, string $decision, ?array $updatedInput = null, ?string $message = null, bool $remember = false): bool
    {
        $task = $approval->task;
        Gate::forUser($by)->authorize('resolveApproval', $task);

        $status = match (true) {
            $decision === 'deny' => ApprovalStatus::Denied,
            $approval->kind === ApprovalKind::Question => ApprovalStatus::Answered,
            default => ApprovalStatus::Allowed,
        };
        // remember = 'always allow': Claude Code applies its own rule suggestions for the session.
        $response = array_filter(['decision' => $decision, 'updated_input' => $updatedInput, 'message' => $message, 'remember' => $remember && $decision === 'allow' ? true : null], fn ($v): bool => $v !== null);

        $won = TaskApproval::query()
            ->whereKey($approval->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->update([
                'status' => $status->value,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
                'response' => json_encode($response),
            ]) === 1;
        if (! $won) {
            return false;
        }

        try {
            $this->daemon->for($task->workspace)->resolveApproval($task->id, $approval->id, $response);
        } catch (DaemonTransportException $e) {
            if ($e->errorCode !== 'unknown_approval') {
                // Not delivered: give the decision back so it can be retried.
                TaskApproval::query()->whereKey($approval->getKey())->update([
                    'status' => ApprovalStatus::Pending->value,
                    'resolved_by' => null,
                    'resolved_at' => null,
                    'response' => null,
                ]);

                throw WorkspaceException::fromDaemon($e);
            }
            // The daemon no longer waits for it (session restarted).
            $status = ApprovalStatus::Cancelled;
            TaskApproval::query()->whereKey($approval->getKey())->update(['status' => $status->value]);
        }

        $event = TaskEvent::query()->create([
            'task_id' => $task->id,
            'type' => TaskEventType::ApprovalResolved,
            'author_user_id' => $by->id,
            'payload' => ['approval_id' => $approval->id, 'status' => $status->value, 'resolved_by_name' => $by->name, 'message' => $message],
        ]);
        $this->publisher->publish($task->workspace_id, $task->id, [[
            'type' => 'approval.resolved',
            'event_id' => $event->id,
            'data' => ['approval_id' => $approval->id, 'status' => $status->value, 'resolved_by_name' => $by->name],
        ]]);
        $this->workspaces->touch($task->workspace);

        return true;
    }

    /**
     * Put a task whose message found no free session in the queue (keeps
     * its place when it is already waiting).
     */
    private function enqueue(Task $task, TaskEvent $event, User $author): void
    {
        if ($task->queued_at === null) {
            $task->forceFill(['queued_at' => now()])->save();
        }
        $position = $this->queuePosition($task);
        $this->publisher->publish($task->workspace_id, $task->id, [[
            'type' => 'user.message',
            'event_id' => $event->id,
            'data' => ['text' => (string) ($event->payload['text'] ?? ''), 'author_id' => $author->id, 'author_name' => $author->name, 'pending' => true, 'position' => $position],
        ]]);
        $this->workspaces->touch($task->workspace);
    }

    /** Place in the workspace's session queue (1 = next), null when not waiting. */
    public function queuePosition(Task $task): ?int
    {
        if ($task->queued_at === null) {
            return null;
        }

        return Task::query()
            ->where('workspace_id', $task->workspace_id)
            ->whereNotNull('queued_at')
            ->where(fn ($q) => $q->where('queued_at', '<', $task->queued_at)
                ->orWhere(fn ($q) => $q->where('queued_at', $task->queued_at)->where('id', '<', $task->id)))
            ->count() + 1;
    }

    /**
     * Give freed sessions to waiting tasks, oldest first: open the session
     * and deliver the waiting messages. A task whose agent is still full
     * keeps waiting without blocking the others; a full workspace stops.
     */
    public function drainQueue(string $workspaceId): void
    {
        $waiting = Task::query()->with('workspace')->where('workspace_id', $workspaceId)->whereNotNull('queued_at')->orderBy('queued_at')->orderBy('id')->get();
        foreach ($waiting as $task) {
            try {
                $this->open($task);
            } catch (SessionsFullException $e) {
                if ($e->scope === 'workspace') {
                    return;
                }

                continue;
            } catch (WorkspaceException) {
                continue;
            }

            $this->deliverPending($task);
        }
    }

    /** Send the messages that waited for a session, in order. */
    private function deliverPending(Task $task): void
    {
        $task->forceFill(['queued_at' => null])->save();
        $pending = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->orderBy('id')->get()
            ->filter(fn (TaskEvent $e): bool => ($e->payload['pending'] ?? false) === true);

        foreach ($pending as $event) {
            $text = (string) ($event->payload['text'] ?? '');
            $payload = [
                'client_msg_id' => (string) $event->id,
                'author' => ['id' => $event->author_user_id, 'name' => (string) ($event->payload['author_name'] ?? '')],
                'text' => $task->restore_note !== null ? '['.$task->restore_note."]\n\n".$text : $text,
            ];
            $images = app(TaskAttachmentService::class)->load(is_array($event->payload['images'] ?? null) ? $event->payload['images'] : []);
            if ($images !== []) {
                $payload['images'] = $images;
            }

            try {
                $this->deliver($task, $payload);
            } catch (WorkspaceException) {
                // Back in line; the next freed session tries again.
                $task->forceFill(['queued_at' => now()])->save();

                return;
            }

            $event->forceFill(['payload' => array_diff_key($event->payload, ['pending' => true])])->save();
            if ($task->restore_note !== null) {
                $task->forceFill(['restore_note' => null])->save();
            }
            $this->publisher->publish($task->workspace_id, $task->id, [[
                'type' => 'user.delivered',
                'event_id' => $event->id,
                'data' => [],
            ]]);
        }
    }

    /**
     * Close the longest-resting session in the scope (an idle session nobody
     * waits on) to make room. Resting tasks hold no session for long.
     */
    private function evictIdle(Task $for, string $scope): bool
    {
        $candidate = Task::query()
            ->where('session_open', true)
            ->where('session_status', SessionStatus::Idle->value)
            ->whereKeyNot($for->id)
            ->when($scope === 'workspace', fn ($q) => $q->where('workspace_id', $for->workspace_id))
            ->when($scope !== 'workspace', fn ($q) => $q->where('agent_credential_id', $for->agent_credential_id))
            ->whereDoesntHave('approvals', fn ($q) => $q->where('status', ApprovalStatus::Pending->value))
            ->where(fn ($q) => $q->whereNull('auto_state')->orWhereIn('auto_state', [AutoTaskState::Paused->value, AutoTaskState::Done->value, AutoTaskState::Failed->value, AutoTaskState::BudgetExceeded->value]))
            ->orderBy('updated_at')
            ->first();
        if ($candidate === null) {
            return false;
        }

        $this->close($candidate, drain: false);

        return true;
    }

    /** End the session and free the agent slot; waiting tasks get it. */
    public function close(Task $task, ?User $by = null, bool $drain = true): void
    {
        if ($by !== null) {
            Gate::forUser($by)->authorize('update', $task);
        }

        if ($task->session_open) {
            try {
                $this->daemon->for($task->workspace)->stopTask($task->id);
            } catch (DaemonTransportException) {
                // container gone — nothing left to stop
            }
        }
        $this->pool->release($task);
        $task->forceFill(['session_status' => SessionStatus::Closed])->save();
        if ($drain) {
            DrainSessionQueueJob::dispatch($task->workspace_id);
        }
    }

    /**
     * Invite a workspace member to collaborate (write, approve, review,
     * ship). Readers of the workspace become collaborators of this task only.
     */
    public function invite(Task $task, User $by, User $member): void
    {
        Gate::forUser($by)->authorize('invite', $task);
        // Working together on a task is pair programming: behind the same gate as workspace members.
        if (! app(WorkspaceMemberService::class)->collaborationEnabled($by)) {
            throw WorkspaceException::translated('workspaces.errors.collaboration_disabled');
        }
        if ($task->workspace->roleOf($member) === null) {
            throw WorkspaceException::translated('workspaces.errors.not_a_member', ['name' => $member->name]);
        }
        if ($task->isOwnedBy($member) || $task->members()->where('user_id', $member->id)->exists()) {
            return;
        }

        TaskMember::query()->create(['task_id' => $task->id, 'user_id' => $member->id, 'invited_by' => $by->id]);
        $this->notice($task, 'invited', $by, ['member_name' => $member->name]);
        app(WorkspaceNotifier::class)->notify($task->workspace, $task, 'invite', __('workspaces.notifications.invite', ['name' => $by->name]), $task->title, $by->id);
    }

    public function uninvite(Task $task, User $by, User $member): void
    {
        Gate::forUser($by)->authorize('invite', $task);
        if ($task->members()->where('user_id', $member->id)->delete() > 0) {
            $this->notice($task, 'uninvited', $by, ['member_name' => $member->name]);
        }
    }

    /**
     * Hand the task to another person: from now on it runs on their
     * subscription (the open session ends). The previous owner stays on as a
     * collaborator.
     */
    public function transfer(Task $task, User $by, User $newOwner, ?AgentCredential $agent = null): void
    {
        Gate::forUser($by)->authorize('transfer', $task);
        if ($task->isOwnedBy($newOwner)) {
            return;
        }
        $role = $task->workspace->roleOf($newOwner);
        if ($role === null || ! $role->grants(Capability::TaskCreate)) {
            throw WorkspaceException::translated('workspaces.errors.transfer_needs_member', ['name' => $newOwner->name]);
        }
        $agent ??= $this->pool->usableAgentsFor($newOwner)->first(fn (AgentCredential $a): bool => $a->user_id === $newOwner->id)
            ?? $this->pool->usableAgentsFor($newOwner)->first();
        if ($agent === null || ! Gate::forUser($newOwner)->allows('use', $agent)) {
            throw WorkspaceException::translated('workspaces.errors.transfer_needs_agent', ['name' => $newOwner->name]);
        }

        if ($task->session_open) {
            $this->close($task);
        }
        $previous = $task->owner;
        $task->forceFill(['owner_user_id' => $newOwner->id, 'agent_credential_id' => $agent->id])->save();
        $task->members()->where('user_id', $newOwner->id)->delete();
        if ($previous !== null) {
            TaskMember::query()->firstOrCreate(['task_id' => $task->id, 'user_id' => $previous->id], ['invited_by' => $by->id]);
        }
        $task->unsetRelation('members');

        $this->notice($task, 'transferred', $by, ['owner_name' => $newOwner->name]);
        app(WorkspaceNotifier::class)->notify($task->workspace, $task, 'invite', __('workspaces.notifications.transferred', ['name' => $by->name]), $task->title, $by->id);
    }

    /** Stop working on a task without merging (its pull request stays as it is). */
    public function cancel(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('archive', $task);
        if ($task->status->isFinished()) {
            return;
        }
        if ($task->session_open) {
            $this->close($task);
        }
        $task->forceFill([
            'status' => TaskStatus::Cancelled,
            'done_at' => now(),
            'queued_at' => null,
            'auto_state' => $task->auto_state?->isRunning() === true ? AutoTaskState::Paused : $task->auto_state,
        ])->save();
        $this->notice($task, 'cancelled', $by);
    }

    /**
     * Delete a task for good: its session, preview, worktree and database go
     * (the remote branch and pull request stay). Archiving is the normal way.
     */
    public function discard(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('delete', $task);
        if ($task->session_open) {
            $this->close($task);
        }
        app(TaskCleanup::class)->cleanup($task);
        $task->delete();
    }

    /**
     * Continue finished work in a new task: same ticket, a fresh branch from
     * the current base, and a link to the earlier task.
     */
    public function followUp(Task $parent, User $by, AgentCredential $agent): Task
    {
        Gate::forUser($by)->authorize('view', $parent);

        return $this->create($parent->workspace, $by, $agent, mb_substr(__('workspaces.task.follow_up_title', ['title' => $parent->title]), 0, 120), $parent->model, options: [
            'base_branch' => $parent->base_branch,
            'external_ref' => $parent->external_ref,
            'parent_task_id' => $parent->id,
        ]);
    }

    /**
     * The first message of a follow-up: what the earlier task was about and
     * where it ended (prefilled, never sent automatically).
     */
    public function followUpBrief(Task $task): string
    {
        $parent = $task->parent;
        if ($parent === null) {
            return '';
        }
        $last = TaskEvent::query()->where('task_id', $parent->id)->where('type', TaskEventType::AssistantMessage->value)->latest('id')->value('payload');

        return trim(implode("\n\n", array_filter([
            __('workspaces.task.follow_up_intro', ['title' => $parent->title, 'branch' => (string) $parent->branch]),
            $parent->pr_url !== null ? __('workspaces.task.follow_up_pr', ['url' => $parent->pr_url, 'state' => (string) $parent->pr_state]) : null,
            is_array($last) && trim((string) ($last['text'] ?? '')) !== '' ? __('workspaces.task.follow_up_last')."\n> ".str_replace("\n", "\n> ", mb_strimwidth(trim((string) $last['text']), 0, 1500, '…')) : null,
        ])));
    }

    public function rename(Task $task, User $by, string $title): void
    {
        Gate::forUser($by)->authorize('update', $task);
        $task->forceFill(['title' => $title])->save();
    }

    /** Hide a task from the list (closing its session first). */
    public function archive(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('archive', $task);
        if ($task->session_open) {
            $this->close($task);
        }
        $task->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('archive', $task);
        $task->forceFill(['archived_at' => null])->save();
    }

    /** Text appended to the Claude Code system prompt of every session. */
    public function systemPrompt(Task $task): string
    {
        $workspace = $task->workspace;

        return implode("\n", [
            'You are working inside an Argos workspace (an interactive, browser-based Claude Code session).',
            "Project: {$workspace->name}. Task: {$task->title}. Branch: {$task->branch} (based on {$task->base_branch}).",
            'Several humans may write in this task; every user message starts with the author in brackets, e.g. "[Ada]: …". Address people by name when it helps.',
            "Work only in this task's own folder ({$task->worktree_path}): it has its own branch, database and preview. Other tasks of the workspace have their own folders — leave them alone.",
            'Humans may edit files in the editor while you work — re-read a file before changing it if it might have moved on.',
            'Do not push and do not open pull requests: the humans ship through Argos. Commit only when someone asks you to.',
        ]);
    }

    private function assertModeAllowed(Workspace $workspace, User $by, PermissionMode $mode): void
    {
        if ($mode === PermissionMode::BypassPermissions && ! $by->isAdmin() && $workspace->roleOf($by) !== WorkspaceRole::Admin) {
            throw WorkspaceException::translated('workspaces.errors.bypass_owner_only');
        }
    }

    /**
     * Persist a system notice (e.g. "Ada interrupted") in the history and
     * broadcast it.
     *
     * @param  array<string, mixed>  $extra
     */
    public function notice(Task $task, string $kind, User $by, array $extra = []): void
    {
        $event = TaskEvent::query()->create([
            'task_id' => $task->id,
            'type' => TaskEventType::Notice,
            'author_user_id' => $by->id,
            'payload' => ['kind' => $kind, 'author_name' => $by->name, ...$extra],
        ]);

        $this->publisher->publish($task->workspace_id, $task->id, [[
            'type' => 'notice',
            'event_id' => $event->id,
            'data' => ['kind' => $kind, 'author_name' => $by->name, ...$extra],
        ]]);
    }

    /**
     * Hand a message to the daemon. When the daemon lost the session
     * (container restart) the session is reopened and the message sent once
     * more.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WorkspaceException when the message could not be delivered
     */
    private function deliver(Task $task, array $payload): void
    {
        try {
            $this->daemon->for($task->workspace)->sendMessage($task->id, $payload);

            return;
        } catch (DaemonTransportException $e) {
            if (! in_array($e->errorCode, ['unknown_task', 'task_closed'], true)) {
                throw WorkspaceException::fromDaemon($e);
            }
        }

        $this->pool->release($task);
        $this->open($task);
        $this->callDaemon(fn () => $this->daemon->for($task->workspace)->sendMessage($task->id, $payload));
    }

    private function callDaemon(callable $call): void
    {
        try {
            $call();
        } catch (DaemonTransportException $e) {
            throw WorkspaceException::fromDaemon($e);
        }
    }
}
