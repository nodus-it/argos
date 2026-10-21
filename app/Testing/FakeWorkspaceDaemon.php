<?php

declare(strict_types=1);

namespace App\Testing;

use App\Enums\ApprovalKind;
use App\Enums\SessionStatus;
use App\Enums\PermissionMode;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskCheckpoint;
use App\Models\Workspace;
use App\Services\Git\DiffParser;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for a workspace daemon (E2E fake mode). Answers
 * commands by feeding a scripted conversation straight into the
 * TaskEventIngestor — the same path real daemon events take:
 *
 * - a message containing "edit" asks for an Edit approval; deciding it
 *   finishes the turn
 * - any other message streams "Echo: <text>" and completes the turn
 */
class FakeWorkspaceDaemon extends WorkspaceDaemonClient implements WorkspaceDaemonTransport
{
    private ?Workspace $workspace = null;

    /** Bound in place of WorkspaceDaemonClient: hand out a copy per workspace. */
    public function for(Workspace $workspace, int $timeoutSeconds = 15): WorkspaceDaemonTransport
    {
        $daemon = clone $this;
        $daemon->workspace = $workspace;

        return $daemon;
    }

    public function health(): array
    {
        return ['version' => 'fake', 'tasks' => []];
    }

    public function initWorkspace(array $payload): array
    {
        return ['status' => 'ready'];
    }

    public function startTask(string $taskId, array $payload): array
    {
        $this->emit($taskId, [
            ['session.started', ['claude_session_id' => 'fake-'.$taskId, 'model' => $payload['model'] ?? null, 'commands' => [['name' => 'review', 'description' => 'Review the diff', 'argument_hint' => '']]]],
            ['status', ['state' => 'idle']],
        ]);

        return ['state' => 'idle'];
    }

    public function sendMessage(string $taskId, array $payload): array
    {
        $this->assertOpen($taskId);
        $text = (string) ($payload['text'] ?? '');
        $author = (string) ($payload['author']['name'] ?? '');

        $events = [
            ['checkpoint.created', ['client_msg_id' => $payload['client_msg_id'] ?? null, 'label' => '', ...$this->nextCheckpoint($taskId)]],
            ['user.accepted', ['client_msg_id' => $payload['client_msg_id'] ?? '']],
            ['status', ['state' => 'running']],
        ];

        // value() returns the cast enum, not the raw string.
        $mode = Task::query()->whereKey($taskId)->value('permission_mode');
        if ($mode === PermissionMode::Plan || $mode === 'plan') {
            // Plan mode: Claude presents a plan for approval (ExitPlanMode).
            $events[] = ['assistant.message', ['message_id' => 'm-'.Str::random(6), 'text' => 'I looked at the code and have a plan.']];
            $events[] = ['approval.requested', $this->planApproval()];
            $events[] = ['status', ['state' => 'waiting_input']];
        } elseif (str_contains(Str::lower($text), 'edit')) {
            $events[] = ['assistant.message', ['message_id' => 'm-'.Str::random(6), 'text' => "I'll change README.md for {$author}."]];
            $events[] = ['approval.requested', [
                'approval_id' => (string) Str::ulid(),
                'tool_use_id' => 'toolu_fake',
                'kind' => 'tool',
                'tool_name' => 'Edit',
                'input' => ['file_path' => '/workspace/tasks/'.(string) Task::query()->whereKey($taskId)->value('slug').'/README.md', 'old_string' => 'Hello', 'new_string' => 'Hello Argos'],
            ]];
            $events[] = ['status', ['state' => 'waiting_input']];
        } else {
            $messageId = 'm-'.Str::random(6);
            // The real daemon prefixes the author; echo what Claude would see.
            $reply = "Echo: [{$author}]: {$text}";
            foreach (str_split($reply, 8) as $chunk) {
                $events[] = ['assistant.delta', ['message_id' => $messageId, 'text' => $chunk]];
            }
            $events[] = ['assistant.message', ['message_id' => $messageId, 'text' => $reply]];
            $events[] = ['turn.completed', ['session_cost_usd' => 0.01, 'duration_ms' => 1200, 'num_turns' => 1]];
            $events[] = ['status', ['state' => 'idle']];
        }

        $this->emit($taskId, $events);

        return ['queued' => true];
    }

    public function interrupt(string $taskId): void
    {
        $this->emit($taskId, [['status', ['state' => 'idle']]]);
    }

    public function resolveApproval(string $taskId, string $approvalId, array $payload): void
    {
        $allowed = ($payload['decision'] ?? '') === 'allow';
        $kind = TaskApproval::query()->whereKey($approvalId)->value('kind');
        $kind = $kind instanceof ApprovalKind ? $kind->value : $kind;
        if ($kind === 'plan') {
            // Accepted: the plan gets implemented. Rejected: a revised plan.
            $this->emit($taskId, $allowed ? [
                ['status', ['state' => 'running']],
                ['assistant.message', ['message_id' => 'm-'.Str::random(6), 'text' => 'Implemented the plan; tests pass.']],
                ['turn.completed', ['session_cost_usd' => 0.03, 'duration_ms' => 1500, 'num_turns' => 2]],
                ['status', ['state' => 'idle']],
            ] : [
                ['status', ['state' => 'running']],
                ['assistant.message', ['message_id' => 'm-'.Str::random(6), 'text' => 'Revised the plan: '.($payload['message'] ?? '')]],
                ['approval.requested', $this->planApproval()],
                ['status', ['state' => 'waiting_input']],
            ]);

            return;
        }
        if ($kind === 'question') {
            $this->emit($taskId, [
                ['status', ['state' => 'running']],
                ['assistant.message', ['message_id' => 'm-'.Str::random(6), 'text' => 'I will assume the default and continue.']],
                ['turn.completed', ['session_cost_usd' => 0.01, 'duration_ms' => 800, 'num_turns' => 1]],
                ['status', ['state' => 'idle']],
            ]);

            return;
        }
        $this->emit($taskId, [
            ['status', ['state' => 'running']],
            ['tool.started', ['tool_use_id' => 'toolu_fake', 'name' => 'Edit', 'input' => ['file_path' => '/workspace/README.md']]],
            ['tool.result', ['tool_use_id' => 'toolu_fake', 'is_error' => ! $allowed, 'output' => $allowed ? 'File updated' : 'Denied by user']],
            ['assistant.message', ['message_id' => 'm-'.Str::random(6), 'text' => $allowed ? 'Done — README.md is updated.' : 'Okay, I left README.md as it is.']],
            ['turn.completed', ['session_cost_usd' => 0.02, 'duration_ms' => 900, 'num_turns' => 1]],
            ['status', ['state' => 'idle']],
        ]);
    }

    public function setPermissionMode(string $taskId, string $mode): void {}

    /** @return array<string, mixed> */
    private function planApproval(): array
    {
        return [
            'approval_id' => (string) Str::ulid(),
            'tool_use_id' => 'toolu_plan',
            'kind' => 'plan',
            'tool_name' => 'ExitPlanMode',
            'input' => ['plan' => "1. Add the field\n2. Cover it with a test"],
        ];
    }

    public function setModel(string $taskId, string $model): void {}

    public function stopTask(string $taskId): void
    {
        $this->emit($taskId, [['status', ['state' => 'closed']]]);
    }

    /** Back to the defaults (between tests). */
    public static function reset(): void
    {
        self::$prepareOutcome = 'ok';
        self::$prepared = [];
        self::$removed = [];
        self::$diff = null;
        self::$fileContents = [];
        self::$gateOutcome = 'fail';
    }

    /** What the next preparations report: `ok`, `setup_failed` or `failed`. */
    public static string $prepareOutcome = 'ok';

    /** Worktrees prepared / removed (slug => payload), for assertions. */
    public static array $prepared = [];

    /** @var list<string> */
    public static array $removed = [];

    /** Prepares synchronously: the worktree is "there" right away. */
    public function prepareWorktree(string $worktree, array $payload): void
    {
        self::$prepared[$worktree] = $payload;
        $events = [['prepare.step', ['step' => 'worktree']]];
        if (self::$prepareOutcome === 'failed') {
            $events[] = ['prepare.failed', ['code' => 'branch_not_found', 'message' => 'Base branch does not exist on the remote']];
        } else {
            $events[] = ['prepare.step', ['step' => 'setup']];
            $events[] = ['prepare.completed', [
                'path' => '/workspace/tasks/'.$worktree,
                'base_commit' => str_repeat('b', 40),
                'created' => true,
                'setup' => self::$prepareOutcome === 'setup_failed' ? 'failed' : 'ok',
                'log' => "[setup] composer install\n[setup] done\n",
            ]];
        }
        $this->emit((string) $payload['task_id'], $events);
    }

    public function removeWorktree(string $worktree): void
    {
        self::$removed[] = $worktree;
    }

    public function gitStatus(string $worktree): array
    {
        return ['branch' => $this->workspace?->default_branch, 'upstream' => null, 'ahead' => 1, 'behind' => 0, 'dirty' => true, 'files' => [['path' => 'README.md', 'status' => 'M']]];
    }

    /** The working tree's diff against the base branch (tests replace it). */
    public static ?string $diff = null;

    public function gitDiff(string $worktree, string $base, ?string $path = null): array
    {
        $diff = self::$diff ?? "diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-Hello\n+Hello Argos\n";
        if ($path !== null) {
            // The section of that one file.
            $parts = preg_split('/(?=^diff --git )/m', $diff) ?: [];
            $diff = implode('', array_filter($parts, fn (string $p): bool => str_contains(strtok($p, "\n") ?: '', ' b/'.$path)));
        }

        return ['diff' => $diff, 'truncated' => false];
    }

    public function gitChanges(string $worktree, string $base): array
    {
        $files = (new DiffParser)->parse($this->gitDiff($worktree, $base)['diff']);

        return array_map(function (array $f): array {
            $path = $f['to_path'] !== '' ? $f['to_path'] : $f['from_path'];

            return [
                'path' => $path,
                'from_path' => $f['from_path'] !== $path ? $f['from_path'] : null,
                'status' => $f['is_new'] ? 'A' : ($f['is_deleted'] ? 'D' : 'M'),
                'additions' => $f['additions'],
                'deletions' => $f['deletions'],
                'binary' => false,
                'generated' => str_ends_with($path, '.lock'),
                'hash' => sha1(serialize($f['hunks'])),
            ];
        }, $files);
    }

    public function gitFetch(): void {}

    public function files(string $worktree, string $query, int $limit = 20): array
    {
        $all = ['README.md', 'app/Http/Controllers/LoginController.php', 'app/Models/User.php', 'routes/web.php'];

        return array_values(array_slice(array_filter($all, fn (string $f): bool => $query === '' || str_contains(strtolower($f), strtolower($query))), 0, $limit));
    }

    /** Files the fake working tree serves to the review (path => content). */
    public static array $fileContents = [];

    public function fileContent(string $worktree, string $path): array
    {
        return ['content' => self::$fileContents[$path] ?? implode("\n", array_map(fn (int $n): string => 'line '.$n, range(1, 60)))."\n", 'truncated' => false];
    }

    /** What the next gate runs report: `fail` (pest red) or `pass`. */
    public static string $gateOutcome = 'fail';

    /** Gates: pint passes, pest fails with one test (or passes) — enough to drive the fix flow. */
    public function runGates(string $worktree, string $runId, ?string $baseCommit): void
    {
        if ($this->workspace === null) {
            throw new \LogicException('FakeWorkspaceDaemon used without a workspace');
        }

        app(TaskEventIngestor::class)->ingest($this->workspace, [
            ['task_id' => null, 'seq' => 1, 'type' => 'gates.started', 'data' => ['run_id' => $runId, 'baseline' => 'ready']],
            ['task_id' => null, 'seq' => 2, 'type' => 'gates.completed', 'data' => [
                'run_id' => $runId,
                'baseline' => 'ready',
                'gates' => ['artisan' => 'pass', 'pint' => 'pass', 'pest' => self::$gateOutcome === 'pass' ? 'pass' : 'fail', 'phpstan' => 'skip'],
                'logs' => ['artisan' => '', 'pint' => "PASS\n", 'pest' => "FAILED  Tests\\Feature\\LoginTest > redirects home\n"],
            ]],
        ]);
    }

    public function gateFixPrompt(string $worktree, string $gate, string $runId): string
    {
        return "# Quality-Gate-Fix: {$gate}\n\nTests sind fehlgeschlagen.\n";
    }

    public function createCheckpoint(string $worktree, string $taskId, string $label): array
    {
        return [...$this->nextCheckpoint($taskId), 'label' => $label];
    }

    public function checkpointDiff(string $worktree, string $ref): array
    {
        return ['diff' => "diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-Hello\n+Hello Argos\n", 'truncated' => false, 'head' => str_repeat('0', 40)];
    }

    public function restoreCheckpoint(string $worktree, string $ref): array
    {
        $taskId = explode('/', $ref)[3] ?? '';
        $state = Task::query()->whereKey($taskId)->value('session_status');
        $state = $state instanceof SessionStatus ? $state->value : $state;
        if (in_array($state, ['running', 'waiting_input'], true)) {
            throw new DaemonTransportException('Claude is working in this task; interrupt it first', 409, 'task_running');
        }

        return ['before' => [...$this->nextCheckpoint($taskId), 'label' => 'before restore'], 'removed' => []];
    }

    /** @return array{ref: string, n: int, sha: string, head: string} */
    private function nextCheckpoint(string $taskId): array
    {
        $n = (int) TaskCheckpoint::query()->where('task_id', $taskId)->max('n') + 1;

        return ['ref' => "refs/argos/checkpoints/{$taskId}/{$n}", 'n' => $n, 'sha' => str_pad(dechex($n), 40, 'c', STR_PAD_LEFT), 'head' => str_repeat('0', 40)];
    }

    public function gitCommit(string $worktree, string $subject, string $body, array $author): ?array
    {
        return ['sha' => str_repeat('a', 40)];
    }

    public function gitPush(string $worktree, string $branch): void {}

    public function oneshot(array $payload): array
    {
        return ['text' => '', 'structured' => ['subject' => 'docs: greet Argos in the README', 'body' => 'Updates the README greeting.'], 'cost_usd' => 0.001];
    }

    private function assertOpen(string $taskId): void
    {
        // A real daemon forgets sessions on restart; here the slot is the truth.
        if (! (bool) Task::query()->whereKey($taskId)->value('session_open')) {
            throw new DaemonTransportException('Task is not running', 404, 'unknown_task');
        }
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $events
     */
    private function emit(string $taskId, array $events): void
    {
        $seq = (int) Task::query()->whereKey($taskId)->value('last_seq');
        $batch = [];
        foreach ($events as [$type, $data]) {
            $batch[] = ['task_id' => $taskId, 'seq' => ++$seq, 'type' => $type, 'data' => $data];
        }

        if ($this->workspace === null) {
            throw new \LogicException('FakeWorkspaceDaemon used without a workspace');
        }

        app(TaskEventIngestor::class)->ingest($this->workspace, $batch);
    }
}
