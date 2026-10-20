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
}
