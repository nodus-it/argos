<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Integrations\Bitbucket\BitbucketConnector;
use App\Integrations\Bitbucket\Requests\CloseIssue;
use App\Integrations\Bitbucket\Requests\CreateIssueComment;
use App\Integrations\Bitbucket\Requests\GetIssue;
use App\Integrations\Bitbucket\Requests\GetIssueComments;
use App\Integrations\Bitbucket\Requests\ListIssues;
use App\Integrations\Bitbucket\Requests\ListRepositories;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;

class BitbucketIssueTracker implements IssueTrackerContract
{
    private readonly BitbucketConnector $connector;

    public function __construct(string $token)
    {
        // Auth (Basic vs. Bearer) is resolved by the connector from the token shape.
        $this->connector = new BitbucketConnector($token);
    }

    public function listReferences(): array
    {
        $response = $this->connector->send(new ListRepositories);

        if ($response->status() === 403 || $response->status() === 404) {
            return [];
        }

        $response->throw();

        $refs = [];
        foreach ($response->json('values', []) as $repo) {
            $fullName = (string) ($repo['full_name'] ?? '');
            if ($fullName !== '') {
                $refs[$fullName] = $fullName;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Bitbucket returns 403 when the issue tracker is disabled — treat as empty.
        // Labels are filtered locally (OR) by IssueIngestService; Bitbucket
        // filters via a `q` BBQL string, so the raw filter array is not forwarded.
        $response = $this->connector->send(new ListIssues($owner, $project));

        if ($response->status() === 403 || $response->status() === 404) {
            return [];
        }

        $response->throw();

        return $response->json('values', []);
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
    {
        $issue = $this->connector->send(new GetIssue($owner, $project, $issueNumber))->throw()->json();
        $comments = $this->connector->send(new GetIssueComments($owner, $project, $issueNumber))->throw()->json();

        return [
            ...$issue,
            'comments_data' => $comments['values'] ?? [],
            'reactions_data' => [],
        ];
    }

    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array {
        return $this->connector
            ->send(new CreateIssueComment($owner, $project, $issueNumber, $body))
            ->throw()
            ->json();
    }

    public function closeIssue(string $owner, string $project, int|string $issueNumber): void
    {
        $this->connector->send(new CloseIssue($owner, $project, $issueNumber))->throw();
    }

    // Bitbucket is not wired as a task issue-provider (no TaskProviderKind),
    // so the 👍-approval flow does not apply — these satisfy the contract.

    public function commentId(array $createResult): ?string
    {
        $id = $createResult['id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function getCommentReactions(string $owner, string $project, int|string $issueId, int|string $commentId): array
    {
        return [];
    }

    public function userCanApprove(string $owner, string $project, array $reactor): bool
    {
        return false;
    }

    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        throw new \LogicException('verifySignature not implemented yet for Bitbucket');
    }

    public function registerWebhook(string $owner, string $project, string $url, string $secret): array
    {
        throw new \LogicException('registerWebhook not implemented yet for Bitbucket');
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        throw new \LogicException('unregisterWebhook not implemented yet for Bitbucket');
    }

    /**
     * Bitbucket sends issue data directly in the envelope — no unwrapping needed.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        return $envelope;
    }
}
