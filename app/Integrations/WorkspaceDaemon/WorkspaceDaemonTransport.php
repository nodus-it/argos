<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon;

/**
 * Command API of one workspace daemon. Implemented by the HTTP transport
 * (WorkspaceDaemonApi) and by the deterministic E2E fake.
 */
interface WorkspaceDaemonTransport
{
    /** @return array<string, mixed> */
    public function health(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function initWorkspace(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function startTask(string $taskId, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(string $taskId, array $payload): array;

    public function interrupt(string $taskId): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveApproval(string $taskId, string $approvalId, array $payload): void;

    public function setPermissionMode(string $taskId, string $mode): void;

    public function setModel(string $taskId, string $model): void;

    public function stopTask(string $taskId): void;

    /**
     * Create the task's worktree, write its .env and run the setup commands
     * in the background (events: prepare.step / prepare.completed /
     * prepare.failed).
     *
     * @param  array{task_id: string, branch: string, base_branch: string, env: array<string, string>, seq_offset: int}  $payload
     */
    public function prepareWorktree(string $worktree, array $payload): void;

    /** Remove a task's worktree and local branch (remote branch stays). */
    public function removeWorktree(string $worktree): void;

    /**
     * Methods taking `$worktree` act on one task's working copy (its slug).
     */

    /** @return array{branch: string|null, upstream: string|null, ahead: int, behind: int, dirty: bool, files: list<array{path: string, status: string}>} */
    public function gitStatus(string $worktree): array;

    /** @return array{diff: string, truncated: bool} */
    public function gitDiff(string $worktree, string $base, ?string $path = null): array;

    /** @return list<array{path: string, from_path: string|null, status: string, additions: int, deletions: int, binary: bool, generated: bool, hash: string}> */
    public function gitChanges(string $worktree, string $base): array;

    public function gitFetch(): void;

    /**
     * @param  array{name: string, email: string}  $author
     * @return array{sha: string}|null null when there was nothing to commit
     */
    public function gitCommit(string $worktree, string $subject, string $body, array $author): ?array;

    public function gitPush(string $worktree, string $branch): void;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: string, structured: mixed, cost_usd: float|int}
     */
    public function oneshot(array $payload): array;

    /** @return list<string> */
    public function files(string $worktree, string $query, int $limit = 20): array;

    /** @return array{content: string, truncated: bool} */
    public function fileContent(string $worktree, string $path): array;

    /** Start a gate run in the task's worktree; throws `gates_running` while one is active. */
    public function runGates(string $worktree, string $runId, ?string $baseCommit): void;

    public function gateFixPrompt(string $worktree, string $gate, string $runId): string;

    /** @return array{ref: string, n: int, sha: string, head: string|null, label: string} */
    public function createCheckpoint(string $worktree, string $taskId, string $label): array;

    /** @return array{diff: string, truncated: bool, head: string|null} */
    public function checkpointDiff(string $worktree, string $ref): array;

    /**
     * Throws `task_running` while Claude works in this task.
     *
     * @return array{before: array{ref: string, n: int, sha: string, head: string|null, label: string}, removed: list<string>}
     */
    public function restoreCheckpoint(string $worktree, string $ref): array;
}
