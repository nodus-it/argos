<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AgentName;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\ClaudeModel;
use App\Enums\PermissionMode;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Everything humans do in a thread: open the session, write, interrupt,
 * switch mode or model, answer approvals, close. Authorization is checked
 * here (policies), so every entry point — UI, API, MCP — gets the same rules.
 */
class ThreadService
{
    public function __construct(
        private readonly AgentPool $pool,
        private readonly WorkspaceDaemonClient $daemon,
        private readonly WorkspaceService $workspaces,
        private readonly ThreadStreamPublisher $publisher,
    ) {}

    public function create(Workspace $workspace, User $by, AgentCredential $agent, string $title, ?string $model = null, PermissionMode $mode = PermissionMode::Default): Thread
    {
        Gate::forUser($by)->authorize('create', [Thread::class, $workspace]);
        Gate::forUser($by)->authorize('use', $agent);
        if ($agent->agent_name !== AgentName::ClaudeCode) {
            throw WorkspaceException::translated('workspaces.errors.agent_unavailable');
        }
        $this->assertModeAllowed($workspace, $by, $mode);

        $thread = new Thread;
        $thread->forceFill([
            'workspace_id' => $workspace->id,
            'agent_credential_id' => $agent->id,
            'created_by' => $by->id,
            'title' => $title,
            'status' => ThreadStatus::Closed,
            'permission_mode' => $mode,
            'model' => $model ?? $agent->default_model ?? ClaudeModel::Sonnet46->value,
            'last_seq' => 0,
            'cost_usd' => 0,
            'session_open' => false,
        ])->save();

        return $thread;
    }

    /**
     * Start (or resume) the daemon session. Occupies one slot of the agent.
     */
    public function open(Thread $thread): void
    {
        $workspace = $thread->workspace;
        if ($workspace->status !== WorkspaceStatus::Running) {
            throw WorkspaceException::translated('workspaces.errors.not_running');
        }

        $this->pool->acquire($thread);
        $agent = $thread->agentCredential;
        if ($agent === null) {
            $this->pool->release($thread);
            throw WorkspaceException::translated('workspaces.errors.agent_unavailable');
        }

        try {
            $this->daemon->for($workspace)->startThread($thread->id, [
                'agent_id' => $agent->id,
                'oauth_token' => (string) ($agent->credentials['token'] ?? ''),
                'model' => $thread->model,
                'permission_mode' => $thread->permission_mode->value,
                'resume_session_id' => $thread->claude_session_id,
                'seq_offset' => $thread->last_seq,
                'append_system_prompt' => $this->systemPrompt($thread),
            ]);
        } catch (DaemonTransportException $e) {
            $this->pool->release($thread);
            $thread->forceFill(['status' => ThreadStatus::Error])->save();

            throw WorkspaceException::translated('workspaces.errors.daemon_unreachable', ['message' => $e->getMessage()]);
        }

        $thread->forceFill(['status' => ThreadStatus::Starting])->save();
    }

    /**
     * Post a message as `$author`. Opens the session on demand; while a turn
     * is running the daemon queues it as a follow-up.
     *
     * @param  list<array{media_type: string, data: string, path?: string}>  $images
     */
    public function send(Thread $thread, User $author, string $text, array $images = []): ThreadEvent
    {
        Gate::forUser($author)->authorize('prompt', $thread);

        if (! $thread->session_open) {
            $this->open($thread);
        }

        $event = ThreadEvent::query()->create([
            'thread_id' => $thread->id,
            'type' => ThreadEventType::UserMessage,
            'author_user_id' => $author->id,
            'payload' => [
                'text' => $text,
                'author_name' => $author->name,
                'images' => array_map(fn (array $image): array => ['media_type' => $image['media_type'], 'path' => $image['path'] ?? null], $images),
            ],
        ]);

        $payload = [
            'client_msg_id' => (string) $event->id,
            'author' => ['id' => $author->id, 'name' => $author->name],
            'text' => $text,
        ];
        if ($images !== []) {
            $payload['images'] = array_map(fn (array $image): array => ['media_type' => $image['media_type'], 'data' => $image['data']], $images);
        }

        try {
            $this->daemon->for($thread->workspace)->sendMessage($thread->id, $payload);
        } catch (DaemonTransportException $e) {
            // The daemon lost the session (container restart): reopen once.
            if (! in_array($e->errorCode, ['unknown_thread', 'thread_closed'], true)) {
                throw WorkspaceException::translated('workspaces.errors.daemon_unreachable', ['message' => $e->getMessage()]);
            }
            $this->pool->release($thread);
            $this->open($thread);
            $this->daemon->for($thread->workspace)->sendMessage($thread->id, $payload);
        }

        $this->publisher->publish($thread->workspace_id, $thread->id, [[
            'type' => 'user.message',
            'event_id' => $event->id,
            'data' => ['text' => $text, 'author_id' => $author->id, 'author_name' => $author->name],
        ]]);
        $this->workspaces->touch($thread->workspace);

        return $event;
    }

    public function interrupt(Thread $thread, User $by): void
    {
        Gate::forUser($by)->authorize('interrupt', $thread);
        if (! $thread->session_open) {
            return;
        }

        $this->callDaemon(fn () => $this->daemon->for($thread->workspace)->interrupt($thread->id));
        $this->notice($thread, 'interrupted', $by);
    }

    public function setPermissionMode(Thread $thread, User $by, PermissionMode $mode): void
    {
        Gate::forUser($by)->authorize('changeMode', $thread);
        $this->assertModeAllowed($thread->workspace, $by, $mode);

        $thread->forceFill(['permission_mode' => $mode])->save();
        if ($thread->session_open) {
            $this->callDaemon(fn () => $this->daemon->for($thread->workspace)->setPermissionMode($thread->id, $mode->value));
        }
        $this->notice($thread, 'permission_mode', $by, ['mode' => $mode->value]);
    }

    public function setModel(Thread $thread, User $by, string $model): void
    {
        Gate::forUser($by)->authorize('changeMode', $thread);

        $thread->forceFill(['model' => $model])->save();
        if ($thread->session_open) {
            $this->callDaemon(fn () => $this->daemon->for($thread->workspace)->setModel($thread->id, $model));
        }
        $this->notice($thread, 'model', $by, ['model' => $model]);
    }

    /**
     * Answer a pending approval. The first decision wins; returns false when
     * someone else already decided.
     *
     * @param  array<string, mixed>|null  $updatedInput
     */
    public function resolveApproval(ThreadApproval $approval, User $by, string $decision, ?array $updatedInput = null, ?string $message = null): bool
    {
        $thread = $approval->thread;
        Gate::forUser($by)->authorize('resolveApproval', $thread);

        $status = match (true) {
            $decision === 'deny' => ApprovalStatus::Denied,
            $approval->kind === ApprovalKind::Question => ApprovalStatus::Answered,
            default => ApprovalStatus::Allowed,
        };
        $response = array_filter(['decision' => $decision, 'updated_input' => $updatedInput, 'message' => $message], fn ($v): bool => $v !== null);

        $won = ThreadApproval::query()
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
            $this->daemon->for($thread->workspace)->resolveApproval($thread->id, $approval->id, $response);
        } catch (DaemonTransportException $e) {
            if ($e->errorCode !== 'unknown_approval') {
                throw WorkspaceException::translated('workspaces.errors.daemon_unreachable', ['message' => $e->getMessage()]);
            }
            // The daemon no longer waits for it (session restarted).
            $status = ApprovalStatus::Cancelled;
            ThreadApproval::query()->whereKey($approval->getKey())->update(['status' => $status->value]);
        }

        $event = ThreadEvent::query()->create([
            'thread_id' => $thread->id,
            'type' => ThreadEventType::ApprovalResolved,
            'author_user_id' => $by->id,
            'payload' => ['approval_id' => $approval->id, 'status' => $status->value, 'resolved_by_name' => $by->name, 'message' => $message],
        ]);
        $this->publisher->publish($thread->workspace_id, $thread->id, [[
            'type' => 'approval.resolved',
            'event_id' => $event->id,
            'data' => ['approval_id' => $approval->id, 'status' => $status->value, 'resolved_by_name' => $by->name],
        ]]);
        $this->workspaces->touch($thread->workspace);

        return true;
    }

    /** End the session and free the agent slot. */
    public function close(Thread $thread, ?User $by = null): void
    {
        if ($by !== null) {
            Gate::forUser($by)->authorize('update', $thread);
        }

        if ($thread->session_open) {
            try {
                $this->daemon->for($thread->workspace)->stopThread($thread->id);
            } catch (DaemonTransportException) {
                // container gone — nothing left to stop
            }
        }
        $this->pool->release($thread);
        $thread->forceFill(['status' => ThreadStatus::Closed])->save();
    }

    public function rename(Thread $thread, User $by, string $title): void
    {
        Gate::forUser($by)->authorize('update', $thread);
        $thread->forceFill(['title' => $title])->save();
    }

    /** Text appended to the Claude Code system prompt of every session. */
    public function systemPrompt(Thread $thread): string
    {
        $workspace = $thread->workspace;

        return implode("\n", [
            'You are working inside an Argos workspace (an interactive, browser-based Claude Code session).',
            "Project: {$workspace->repoProfile->name}. Branch: {$workspace->branch} (based on {$workspace->base_branch}).",
            'Several humans may write in this thread; every user message starts with the author in brackets, e.g. "[Ada]: …". Address people by name when it helps.',
            'Humans may edit files in the editor while you work — re-read a file before changing it if it might have moved on.',
            'Do not push and do not open pull requests: the humans ship through Argos. Commit only when someone asks you to.',
        ]);
    }

    private function assertModeAllowed(Workspace $workspace, User $by, PermissionMode $mode): void
    {
        if ($mode === PermissionMode::BypassPermissions && $workspace->roleOf($by) !== WorkspaceRole::Owner) {
            throw WorkspaceException::translated('workspaces.errors.bypass_owner_only');
        }
    }

    /**
     * Persist a system notice (e.g. "Ada interrupted") in the history.
     *
     * @param  array<string, mixed>  $extra
     */
    private function notice(Thread $thread, string $kind, User $by, array $extra = []): void
    {
        $event = DB::transaction(fn (): ThreadEvent => ThreadEvent::query()->create([
            'thread_id' => $thread->id,
            'type' => ThreadEventType::Notice,
            'author_user_id' => $by->id,
            'payload' => ['kind' => $kind, 'author_name' => $by->name, ...$extra],
        ]));

        $this->publisher->publish($thread->workspace_id, $thread->id, [[
            'type' => 'notice',
            'event_id' => $event->id,
            'data' => ['kind' => $kind, 'author_name' => $by->name, ...$extra],
        ]]);
    }

    private function callDaemon(callable $call): void
    {
        try {
            $call();
        } catch (DaemonTransportException $e) {
            throw WorkspaceException::translated('workspaces.errors.daemon_unreachable', ['message' => $e->getMessage()]);
        }
    }
}
