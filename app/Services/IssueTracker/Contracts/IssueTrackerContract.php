<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Contracts;

interface IssueTrackerContract
{
    /**
     * @param  array<string, mixed>  $filters  e.g. state, labels, assignee, milestone
     * @return array<int, array<string, mixed>>
     */
    public function listIssues(string $owner, string $project, array $filters = []): array;

    /**
     * Returns issue with content, comments, reactions and metadata combined.
     *
     * @return array<string, mixed>
     */
    public function getIssue(string $owner, string $project, int $issueNumber): array;

    /**
     * @param  array<string, mixed>  $options  e.g. labels, assignees, milestone
     * @return array<string, mixed>
     */
    public function createIssue(
        string $owner,
        string $project,
        string $title,
        string $body,
        array $options = [],
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function createComment(
        string $owner,
        string $project,
        int $issueNumber,
        string $body,
    ): array;

    /**
     * Verify the webhook payload signature against the shared secret.
     * GitHub uses HMAC-SHA256; GitLab compares a plain token header.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool;

    /**
     * Register a webhook on the provider and return the provider's response
     * (at minimum containing an 'id' key for later unregistration).
     *
     * @return array<string, mixed>
     */
    public function registerWebhook(string $owner, string $project, string $url, string $secret): array;

    /**
     * Remove a previously registered webhook.
     */
    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void;
}
