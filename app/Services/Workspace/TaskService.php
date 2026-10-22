<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AgentName;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\AutoTaskState;
use App\Enums\PermissionMode;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskKind;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Jobs\Workspace\DrainSessionQueueJob;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IssueTracker\IssueRunReporter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A task's life and its Claude session: create, open, write, interrupt,
 * switch mode, model or effort, answer approvals, queue, close, cancel,
 * archive and delete. People (TaskMembershipService), the ticket
 * (TaskTicketService) and notices (TaskNoticeService) have their own. Authorization is checked
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
        private readonly TaskNoticeService $notices,
    ) {}

    /**
     * Create a task owned by `$by`, running on their agent.
     *
     * @param  bool  $automated  an auto-task (AutoTaskDriver): it may run
     *                           in bypass mode — it is isolated in the container
     *                           and nobody is there to answer approvals
     * @param  array{base_branch?: string|null, external_ref?: array<string, mixed>|null, origin?: TaskOrigin, kind?: TaskKind, parent_task_id?: string|null, auto_merge?: bool}  $options
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
            'auto_merge' => (bool) ($options['auto_merge'] ?? false),
        ])->save();

        // Worktree, database and setup commands; Claude starts after that.
        PrepareTaskJob::dispatch($task->id)->afterCommit();
        if ($task->external_ref !== null) {
            app(IssueRunReporter::class)->started($task);
        }

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
                'oauth_token' => $agent->oauthToken(),
                'model' => $task->model,
                'effort' => $task->effort,
                'permission_mode' => $task->permission_mode->value,
                'resume_session_id' => $task->claude_session_id,
                'seq_offset' => $task->last_seq,
                'append_system_prompt' => $this->systemPrompt($task),
                // Claude's own commits carry the person the task belongs to.
                'git_author' => $task->owner !== null ? ['name' => $task->owner->name, 'email' => $task->owner->email] : null,
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
     * @param  array<string, mixed>  $display  how the chat shows the message (e.g. `review`: the collected comments as a card); Claude gets `$text`
     */
    public function send(Task $task, User $author, string $text, array $images = [], bool $automated = false, array $display = []): TaskEvent
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
            ] + ($automated ? ['auto' => true] : []) + ($queued ? ['pending' => true] : []) + $display,
        ]);

        if ($queued) {
            $this->enqueue($task, $event, $author);

            return $event;
        }

        $payload = $this->messagePayload($task, $event, ['id' => $author->id, 'name' => $author->name], $text, array_map(fn (array $image): array => ['media_type' => $image['media_type'], 'data' => $image['data']], $images));

        try {
            $this->deliver($task, $payload);
        } catch (WorkspaceException $e) {
            $event->delete();

            throw $e;
        }

        $this->restoreNoteDelivered($task);

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

        WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace)->interrupt($task->id));
        $this->notices->notice($task, 'interrupted', $by);
    }

    public function setPermissionMode(Task $task, User $by, PermissionMode $mode): void
    {
        Gate::forUser($by)->authorize('changeMode', $task);
        $this->assertModeAllowed($task->workspace, $by, $mode);

        $task->forceFill(['permission_mode' => $mode])->save();
        if ($task->session_open) {
            WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace)->setPermissionMode($task->id, $mode->value));
        }
        $this->notices->notice($task, 'permission_mode', $by, ['mode' => $mode->value]);
    }

    /**
     * Switch the mode for an auto-task (AutoTaskDriver) — no policy or
     * owner check: the driver acts for the task's creator.
     */
    public function applyAutomatedMode(Task $task, PermissionMode $mode): void
    {
        $task->forceFill(['permission_mode' => $mode])->save();
        if ($task->session_open) {
            WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace)->setPermissionMode($task->id, $mode->value));
        }
    }

    public function setModel(Task $task, User $by, string $model): void
    {
        Gate::forUser($by)->authorize('changeMode', $task);

        $task->forceFill(['model' => $model])->save();
        if ($task->session_open) {
            WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace)->setModel($task->id, $model));
        }
        $this->notices->notice($task, 'model', $by, ['model' => $model]);
    }

    /** Claude Code's /effort for the task; null goes back to the model's default. */
    public function setEffort(Task $task, User $by, ?string $effort): void
    {
        Gate::forUser($by)->authorize('changeMode', $task);
        if ($effort !== null && ! in_array($effort, ModelCatalog::EFFORT_LEVELS, true)) {
            throw WorkspaceException::translated('workspaces.models.effort_invalid');
        }

        $task->forceFill(['effort' => $effort])->save();
        if ($task->session_open) {
            WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace)->setEffort($task->id, $effort));
        }
        $this->notices->notice($task, 'effort', $by, ['effort' => $effort]);
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

    /**
     * What the daemon gets for a message. After a restore Claude's context
     * is out of date: the first message says so.
     *
     * @param  array{id: int|null, name: string}  $author
     * @param  list<array{media_type: string, data: string}>  $images
     * @return array<string, mixed>
     */
    private function messagePayload(Task $task, TaskEvent $event, array $author, string $text, array $images): array
    {
        return [
            'client_msg_id' => (string) $event->id,
            'author' => $author,
            'text' => $task->restore_note !== null ? '['.$task->restore_note."]\n\n".$text : $text,
            ...($images !== [] ? ['images' => $images] : []),
        ];
    }

    /** The note went out with a message — only once. */
    private function restoreNoteDelivered(Task $task): void
    {
        if ($task->restore_note !== null) {
            $task->forceFill(['restore_note' => null])->save();
        }
    }

    /** Send the messages that waited for a session, in order. */
    private function deliverPending(Task $task): void
    {
        $task->forceFill(['queued_at' => null])->save();
        $pending = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->orderBy('id')->get()
            ->filter(fn (TaskEvent $e): bool => ($e->payload['pending'] ?? false) === true);

        foreach ($pending as $event) {
            $payload = $this->messagePayload(
                $task,
                $event,
                ['id' => $event->author_user_id, 'name' => (string) ($event->payload['author_name'] ?? '')],
                (string) ($event->payload['text'] ?? ''),
                app(TaskAttachmentService::class)->load(is_array($event->payload['images'] ?? null) ? $event->payload['images'] : []),
            );

            try {
                $this->deliver($task, $payload);
            } catch (WorkspaceException) {
                // Back in line; the next freed session tries again.
                $task->forceFill(['queued_at' => now()])->save();

                return;
            }

            $event->forceFill(['payload' => array_diff_key($event->payload, ['pending' => true])])->save();
            $this->restoreNoteDelivered($task);
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
            ->where(fn ($q) => $q->whereNull('auto_state')->orWhereIn('auto_state', AutoTaskState::idleValues()))
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
        $this->notices->notice($task, 'cancelled', $by);
        app(IssueRunReporter::class)->closed($task);
        $this->endPreview($task);
    }

    /** A finished task's preview ends with it (failures are only logged). */
    public function endPreview(Task $task): void
    {
        try {
            app(WorkspacePreviewService::class)->stop($task);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Stopping the preview of a finished task failed', ['task' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Delete a task for good: its session, preview, worktree and database go
     * (the remote branch and pull request stay). Archiving is the normal way.
     */
    public function discard(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('delete', $task);
        // Its worktree and database go first — a task deleted without them
        // would leave both behind in the workspace.
        if (! app(TaskCleanup::class)->cleanup($task)) {
            throw WorkspaceException::translated('workspaces.errors.discard_needs_running');
        }
        if ($task->session_open) {
            $this->close($task);
        }
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
            'Commit on your own whenever a self-contained, working part is done and checked (tests and linters you ran are green): one logical change per commit, a Conventional Commits message (feat:, fix:, refactor:, test:, docs:, chore:; subject ≤ 72 characters, imperative; a short body with what and why), ending with the trailer "Co-Authored-By: Claude <noreply@anthropic.com>". The git author is already set to the task owner — do not change it.',
            'Never push, never rewrite pushed history and never open pull requests: the humans push and open the pull request through Argos.',
            'Do what is asked directly in the code. Write a concept, plan or design document only when someone asks for one in so many words; when a request is unclear, ask in the chat instead.',
        ]);
    }

    private function assertModeAllowed(Workspace $workspace, User $by, PermissionMode $mode): void
    {
        if ($mode === PermissionMode::BypassPermissions && ! $by->can('bypassPermissions', $workspace)) {
            throw WorkspaceException::translated('workspaces.errors.bypass_owner_only');
        }
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
        WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace)->sendMessage($task->id, $payload));
    }

}
