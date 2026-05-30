<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Contracts;

interface IssueTrackerContract
{
    /**
     * List the references a binding can be scoped to, for the given account's token.
     *
     * The array key is the exact value that belongs in
     * TaskProviderBinding::$external_project_ref (and is consumed by parseRef);
     * the value is a human-readable label for the UI. Examples:
     *   - GitHub/GitLab/Bitbucket: 'owner/repo' => 'owner/repo'
     *   - Linear:                  'ENG' => 'ENG — Engineering' (team key)
     *
     * @return array<string, string>
     */
    public function listReferences(): array;

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
    public function getIssue(string $owner, string $project, int|string $issueNumber): array;

    /**
     * @return array<string, mixed>
     */
    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array;

    /**
     * Extract the provider's comment id from a createComment() response, so it
     * can be stored and later polled for reactions. Null when not derivable.
     *
     * @param  array<string, mixed>  $createResult
     */
    public function commentId(array $createResult): ?string;

    /**
     * Reactions on a comment, normalised to a list of
     * ['emoji' => string, 'user_id' => string, 'user_login' => string].
     * The issue id is required because some providers (GitLab) address a
     * comment only in the context of its issue.
     *
     * @return list<array{emoji: string, user_id: string, user_login: string}>
     */
    public function getCommentReactions(string $owner, string $project, int|string $issueId, int|string $commentId): array;

    /**
     * Whether the given reactor is allowed to approve work on this project —
     * i.e. has write/admin access (GitHub/GitLab) or is a full org member
     * (Linear). Used to gate the 👍-to-start-implement flow so not just anyone
     * can trigger it.
     *
     * @param  array{emoji: string, user_id: string, user_login: string}  $reactor
     */
    public function userCanApprove(string $owner, string $project, array $reactor): bool;

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
