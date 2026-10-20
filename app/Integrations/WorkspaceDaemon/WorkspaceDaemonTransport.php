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
    public function startThread(string $threadId, array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(string $threadId, array $payload): array;

    public function interrupt(string $threadId): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveApproval(string $threadId, string $approvalId, array $payload): void;

    public function setPermissionMode(string $threadId, string $mode): void;

    public function setModel(string $threadId, string $model): void;

    public function stopThread(string $threadId): void;

    /** @return array{branch: string|null, upstream: string|null, ahead: int, behind: int, dirty: bool, files: list<array{path: string, status: string}>} */
    public function gitStatus(): array;

    /** @return array{diff: string, truncated: bool} */
    public function gitDiff(string $base): array;

    public function gitFetch(): void;

    /**
     * @param  array{name: string, email: string}  $author
     * @return array{sha: string}|null null when there was nothing to commit
     */
    public function gitCommit(string $subject, string $body, array $author): ?array;

    public function gitPush(string $branch): void;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: string, structured: mixed, cost_usd: float|int}
     */
    public function oneshot(array $payload): array;

    /** @return list<string> */
    public function files(string $query, int $limit = 20): array;

    /** Start a gate run; throws `gates_running` while one is active. */
    public function runGates(string $runId): void;

    public function gateFixPrompt(string $gate, string $runId): string;

    /** @return array{ref: string, n: int, sha: string, head: string|null, label: string} */
    public function createCheckpoint(string $threadId, string $label): array;

    /** @return array{diff: string, truncated: bool, head: string|null} */
    public function checkpointDiff(string $ref): array;

    /**
     * Throws `thread_running` while Claude works in the checkpoint's thread.
     *
     * @return array{before: array{ref: string, n: int, sha: string, head: string|null, label: string}, removed: list<string>}
     */
    public function restoreCheckpoint(string $ref): array;
}
