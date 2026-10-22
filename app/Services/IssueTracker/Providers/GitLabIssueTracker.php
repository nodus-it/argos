<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Providers;

use App\Support\RepoUrlBuilder;
use App\Integrations\GitLab\GitLabConnector;
use App\Integrations\GitLab\Requests\CloseIssue;
use App\Integrations\GitLab\Requests\CreateIssue;
use App\Integrations\GitLab\Requests\CreateIssueNote;
use App\Integrations\GitLab\Requests\FindUserByUsername;
use App\Integrations\GitLab\Requests\GetIssue;
use App\Integrations\GitLab\Requests\GetIssueAwardEmoji;
use App\Integrations\GitLab\Requests\GetIssueNotes;
use App\Integrations\GitLab\Requests\GetNoteAwardEmoji;
use App\Integrations\GitLab\Requests\GetProjectMember;
use App\Integrations\GitLab\Requests\ListIssues;
use App\Integrations\GitLab\Requests\ListProjects;
use App\Integrations\GitLab\Requests\RegisterWebhook;
use App\Integrations\GitLab\Requests\UnregisterWebhook;
use App\Integrations\GitLab\Requests\UpdateIssueAssignees;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Support\Facades\Log;

class GitLabIssueTracker implements IssueTrackerContract
{
    private readonly GitLabConnector $connector;

    public function __construct(string $token, string $instanceUrl = RepoUrlBuilder::DEFAULT_GITLAB_INSTANCE)
    {
        $this->connector = new GitLabConnector($token, $instanceUrl);
    }

    public function listReferences(): array
    {
        $response = $this->connector->send(new ListProjects(simple: true))->throw();

        $refs = [];
        foreach ($response->json() as $project) {
            $path = (string) ($project['path_with_namespace'] ?? '');
            if ($path !== '') {
                $refs[$path] = $path;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Only forward `state` to the API; labels are filtered locally (OR
        // semantics) by IssueIngestService. GitLab's `labels` param is AND-only
        // and expects a comma string, not the filter array.
        $state = isset($filters['state']) && is_string($filters['state']) && $filters['state'] !== ''
            ? $filters['state']
            : 'opened';

        $issues = [];
        $page = null;

        do {
            $response = $this->connector->send(new ListIssues($owner, $project, $state, $page))->throw();

            $issues = array_merge($issues, $response->json());

            $header = $response->header('X-Next-Page');
            $page = is_string($header) && $header !== '' ? (int) $header : null;
        } while ($page !== null);

        return $issues;
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
    {
        $issue = $this->connector->send(new GetIssue($owner, $project, $issueNumber))->throw()->json();
        $notes = $this->connector->send(new GetIssueNotes($owner, $project, $issueNumber))->throw()->json();

        // Award emojis require a GitLab plan that supports them — treat 404/403 as empty.
        $awardEmojis = rescue(
            fn () => $this->connector->send(new GetIssueAwardEmoji($owner, $project, $issueNumber))->throw()->json(),
            [],
        );

        return [
            ...$issue,
            'comments_data' => $notes,
            'reactions_data' => $awardEmojis,
        ];
    }

    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array {
        return $this->connector
            ->send(new CreateIssueNote($owner, $project, $issueNumber, $body))
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
        $notes = $this->connector
            ->send(new GetIssueNotes($owner, $project, $issueNumber))
            ->throw()
            ->json();

        $out = [];
        foreach (is_array($notes) ? $notes : [] as $note) {
            // System notes ("changed the description", "assigned to …") are
            // activity, not comments.
            if (! is_array($note) || ($note['system'] ?? false) === true) {
                continue;
            }
            $out[] = [
                'id' => (string) ($note['id'] ?? ''),
                'author' => (string) ($note['author']['username'] ?? ''),
                'body' => (string) ($note['body'] ?? ''),
                'created_at' => isset($note['created_at']) ? (string) $note['created_at'] : null,
                // The notes API carries no web URL for a note.
                'url' => null,
            ];
        }

        // GitLab returns notes newest first by default.
        usort($out, fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

        return $out;
    }

    public function assignIssue(string $owner, string $project, int|string $issueNumber, string $assignee): bool
    {
        $context = ['provider' => 'gitlab', 'project' => "{$owner}/{$project}", 'issue' => (string) $issueNumber, 'assignee' => $assignee];

        try {
            $users = $this->connector->send(new FindUserByUsername($assignee))->throw()->json();
            $userId = is_array($users) && is_array($users[0] ?? null) ? ($users[0]['id'] ?? null) : null;

            if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
                Log::channel('argos')->info('Issue assignee not found', $context);

                return false;
            }

            $response = $this->connector->send(new UpdateIssueAssignees($owner, $project, $issueNumber, [(int) $userId]));
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
        $awards = $this->connector
            ->send(new GetNoteAwardEmoji($owner, $project, $issueId, $commentId))
            ->throw()
            ->json();

        $out = [];
        foreach ($awards as $award) {
            $out[] = [
                'emoji' => (string) ($award['name'] ?? ''),
                'user_id' => (string) ($award['user']['id'] ?? ''),
                'user_login' => (string) ($award['user']['username'] ?? ''),
            ];
        }

        return $out;
    }

    public function userCanApprove(string $owner, string $project, array $reactor): bool
    {
        $userId = $reactor['user_id'];
        if ($userId === '') {
            return false;
        }

        try {
            $member = $this->connector
                ->send(new GetProjectMember($owner, $project, $userId))
                ->throw()
                ->json();
        } catch (\Throwable) {
            return false;
        }

        // GitLab access levels: 30 = Developer (can push), 40 = Maintainer, 50 = Owner.
        return (int) ($member['access_level'] ?? 0) >= 30;
    }

    /**
     * GitLab sends a plain token in the X-Gitlab-Token header.
     * The $signature parameter carries the header value directly.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        return hash_equals($secret, $signature);
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
     * GitLab sends issue data in object_attributes — extract it for issue events only.
     * Top-level labels (objects with 'title') are merged in as strings.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        if (($envelope['object_kind'] ?? null) !== 'issue') {
            return [];
        }

        $issue = $envelope['object_attributes'] ?? null;

        if (! is_array($issue) || empty($issue)) {
            return [];
        }

        if (isset($envelope['labels']) && is_array($envelope['labels'])) {
            $issue['labels'] = array_map(
                fn (mixed $l): string => is_array($l) ? (string) ($l['title'] ?? '') : (string) $l,
                $envelope['labels'],
            );
        }

        return $issue;
    }
}
