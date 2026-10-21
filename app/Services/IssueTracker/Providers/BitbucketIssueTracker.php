<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Providers;

use App\Integrations\Bitbucket\BitbucketConnector;
use App\Integrations\Bitbucket\Requests\CloseIssue;
use App\Integrations\Bitbucket\Requests\CreateIssue;
use App\Integrations\Bitbucket\Requests\CreateIssueComment;
use App\Integrations\Bitbucket\Requests\GetIssue;
use App\Integrations\Bitbucket\Requests\GetIssueComments;
use App\Integrations\Bitbucket\Requests\ListIssues;
use App\Integrations\Bitbucket\Requests\ListRepositories;
use App\Integrations\Bitbucket\Requests\ListWorkspaceMembers;
use App\Integrations\Bitbucket\Requests\UpdateIssueAssignee;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Support\Facades\Log;

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

    public function createIssue(string $owner, string $project, string $title, string $body): array
    {
        return $this->connector
            ->send(new CreateIssue($owner, $project, $title, $body))
            ->throw()
            ->json();
    }

    public function listComments(string $owner, string $project, int|string $issueNumber): array
    {
        $comments = $this->connector
            ->send(new GetIssueComments($owner, $project, $issueNumber))
            ->throw()
            ->json('values', []);

        $out = [];
        foreach (is_array($comments) ? $comments : [] as $comment) {
            if (! is_array($comment)) {
                continue;
            }
            $user = is_array($comment['user'] ?? null) ? $comment['user'] : [];
            $url = $comment['links']['html']['href'] ?? null;
            $out[] = [
                'id' => (string) ($comment['id'] ?? ''),
                'author' => (string) ($user['nickname'] ?? $user['username'] ?? $user['display_name'] ?? ''),
                'body' => (string) ($comment['content']['raw'] ?? ''),
                'created_at' => isset($comment['created_on']) ? (string) $comment['created_on'] : null,
                'url' => is_string($url) ? $url : null,
            ];
        }

        usort($out, fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

        return $out;
    }

    public function assignIssue(string $owner, string $project, int|string $issueNumber, string $assignee): bool
    {
        $context = ['provider' => 'bitbucket', 'repo' => "{$owner}/{$project}", 'issue' => (string) $issueNumber, 'assignee' => $assignee];

        try {
            $uuid = $this->findWorkspaceMemberUuid($owner, $assignee);
            if ($uuid === null) {
                Log::channel('argos')->info('Issue assignee not found', $context);

                return false;
            }

            $response = $this->connector->send(new UpdateIssueAssignee($owner, $project, $issueNumber, $uuid));
            if ($response->failed()) {
                Log::channel('argos')->info('Issue assignment refused', [...$context, 'status' => $response->status()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('argos')->info('Issue assignment failed', [...$context, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Resolve a nickname/username (or account id) to the member's uuid within
     * the repository's workspace. Null when no member matches.
     */
    private function findWorkspaceMemberUuid(string $workspace, string $login): ?string
    {
        $response = $this->connector->send(new ListWorkspaceMembers($workspace));
        if ($response->failed()) {
            return null;
        }

        $needle = strtolower($login);
        foreach ((array) $response->json('values', []) as $membership) {
            $user = is_array($membership) && is_array($membership['user'] ?? null) ? $membership['user'] : [];
            foreach (['nickname', 'username', 'account_id'] as $key) {
                if (is_string($user[$key] ?? null) && strtolower($user[$key]) === $needle) {
                    $uuid = $user['uuid'] ?? null;

                    return is_string($uuid) && $uuid !== '' ? $uuid : null;
                }
            }
        }

        return null;
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
