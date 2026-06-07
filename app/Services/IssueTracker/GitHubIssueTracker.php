<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Integrations\GitHub\GitHubConnector;
use App\Integrations\GitHub\Requests\CloseIssue;
use App\Integrations\GitHub\Requests\CreateIssueComment;
use App\Integrations\GitHub\Requests\GetCollaboratorPermission;
use App\Integrations\GitHub\Requests\GetCommentReactions;
use App\Integrations\GitHub\Requests\GetIssue;
use App\Integrations\GitHub\Requests\GetIssueComments;
use App\Integrations\GitHub\Requests\GetIssueReactions;
use App\Integrations\GitHub\Requests\ListIssues;
use App\Integrations\GitHub\Requests\ListRepositories;
use App\Integrations\GitHub\Requests\RegisterWebhook;
use App\Integrations\GitHub\Requests\UnregisterWebhook;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;

class GitHubIssueTracker implements IssueTrackerContract
{
    private readonly GitHubConnector $connector;

    public function __construct(string $token)
    {
        $this->connector = new GitHubConnector($token);
    }

    public function listReferences(): array
    {
        $response = $this->connector->send(new ListRepositories)->throw();

        $refs = [];
        foreach ($response->json() as $repo) {
            $fullName = (string) ($repo['full_name'] ?? '');
            if ($fullName !== '') {
                $refs[$fullName] = $fullName;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Only forward `state` to the API. Labels are filtered locally by
        // IssueIngestService (OR semantics); GitHub's `labels` param is AND-only
        // and must be a comma string — passing the filter array as `labels[]`
        // made the endpoint return 422.
        $state = isset($filters['state']) && is_string($filters['state']) && $filters['state'] !== ''
            ? $filters['state']
            : 'open';

        $issues = [];
        $page = null;

        // Paginate by following GitHub's Link header, but only take the page
        // number from it so each request stays a relative endpoint.
        do {
            $response = $this->connector->send(new ListIssues($owner, $project, $state, $page))->throw();

            foreach ($response->json() as $item) {
                // GitHub returns PRs mixed into the issues endpoint; filter them out
                if (isset($item['pull_request'])) {
                    continue;
                }
                $issues[] = $item;
            }

            $link = $response->header('Link');
            $page = $this->nextPage(is_string($link) ? $link : null);
        } while ($page !== null);

        return $issues;
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
    {
        $issue = $this->connector->send(new GetIssue($owner, $project, $issueNumber))->throw()->json();
        $comments = $this->connector->send(new GetIssueComments($owner, $project, $issueNumber))->throw()->json();
        $reactions = $this->connector->send(new GetIssueReactions($owner, $project, $issueNumber))->throw()->json();

        return [
            ...$issue,
            'comments_data' => $comments,
            'reactions_data' => $reactions,
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

    public function commentId(array $createResult): ?string
    {
        $id = $createResult['id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function getCommentReactions(string $owner, string $project, int|string $issueId, int|string $commentId): array
    {
        $reactions = $this->connector
            ->send(new GetCommentReactions($owner, $project, $commentId))
            ->throw()
            ->json();

        $out = [];
        foreach ($reactions as $reaction) {
            $out[] = [
                'emoji' => (string) ($reaction['content'] ?? ''),
                'user_id' => (string) ($reaction['user']['id'] ?? ''),
                'user_login' => (string) ($reaction['user']['login'] ?? ''),
            ];
        }

        return $out;
    }

    public function userCanApprove(string $owner, string $project, array $reactor): bool
    {
        $login = $reactor['user_login'];
        if ($login === '') {
            return false;
        }

        try {
            $data = $this->connector
                ->send(new GetCollaboratorPermission($owner, $project, $login))
                ->throw()
                ->json();
        } catch (\Throwable) {
            return false;
        }

        // GitHub returns "admin" | "write" | "maintain" | "triage" | "read" | "none".
        return in_array((string) ($data['permission'] ?? ''), ['admin', 'write', 'maintain'], true);
    }

    /**
     * GitHub signs payloads with HMAC-SHA256 and sends
     * "sha256=<hex>" in the X-Hub-Signature-256 header.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function registerWebhook(string $owner, string $project, string $url, string $secret): array
    {
        return $this->connector
            ->send(new RegisterWebhook($owner, $project, $url, $secret))
            ->throw()
            ->json();
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        $this->connector->send(new UnregisterWebhook($owner, $project, $webhookId))->throw();
    }

    /**
     * Extract the inner issue payload from a GitHub webhook envelope.
     *
     * GitHub sends: {action, issue:{…}, repository, sender}
     * Only 'issues' events are ingested; 'issue_comment' is ignored.
     * Returns [] for envelopes without an 'issue' key.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        // Only process 'issues' events
        if ($eventType !== 'issues') {
            return [];
        }

        $issue = $envelope['issue'] ?? null;

        if (! is_array($issue) || empty($issue)) {
            return [];
        }

        // Skip if the issue is actually a PR (has a pull_request key)
        if (isset($issue['pull_request'])) {
            return [];
        }

        return $issue;
    }

    /**
     * Take the next page number from a GitHub Link header, or null when there
     * are no more pages. We follow the header for correctness but re-request a
     * relative endpoint with ?page=N rather than the absolute next URL.
     */
    private function nextPage(?string $linkHeader): ?int
    {
        if ($linkHeader === null || $linkHeader === '') {
            return null;
        }

        // Link: <https://api.github.com/repos/…/issues?page=2&per_page=100>; rel="next", …
        if (preg_match('/<[^>]*[?&]page=(\d+)[^>]*>;\s*rel="next"/', $linkHeader, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
