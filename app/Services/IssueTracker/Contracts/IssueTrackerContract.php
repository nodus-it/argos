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

    /**
     * Normalize a raw webhook envelope to the inner issue payload.
     *
     * GitHub wraps the issue in {action, issue:{…}, repository, sender}.
     * GitLab and Bitbucket send the issue data directly, so they return $envelope unchanged.
     * Returns an empty array when the event should be ignored (e.g. PR envelopes, non-issue events).
     *
     * @param  array<string, mixed>  $envelope
     * @param  string|null  $eventType  Provider-specific event type header value
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array;
}
