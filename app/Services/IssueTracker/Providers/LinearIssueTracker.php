<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Providers;

use App\Integrations\Linear\LinearConnector;
use App\Integrations\Linear\Requests\GraphQLRequest;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use RuntimeException;

class LinearIssueTracker implements IssueTrackerContract
{
    private readonly LinearConnector $connector;

    public function __construct(string $token)
    {
        $this->connector = new LinearConnector($token);
    }

    public function listReferences(): array
    {
        $result = $this->graphql('
            query ListTeams {
                teams(first: 250) {
                    nodes { key name }
                }
            }
        ');

        $refs = [];
        foreach ($result['data']['teams']['nodes'] ?? [] as $team) {
            $key = (string) ($team['key'] ?? '');
            if ($key !== '') {
                $name = (string) ($team['name'] ?? '');
                $refs[$key] = $name !== '' ? "{$key} — {$name}" : $key;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // $owner carries the team key (e.g. 'ENG'); $project is empty for Linear.
        $teamId = $this->resolveTeamId($owner);

        $result = $this->graphql('
            query ListIssues($filter: IssueFilter) {
                issues(filter: $filter, first: 250) {
                    nodes {
                        id
                        title
                        description
                        url
                        state { name }
                        labels { nodes { name } }
                    }
                }
            }
        ', [
            'filter' => [
                'team' => ['id' => ['eq' => $teamId]],
            ],
        ]);

        $issues = [];
        foreach ($result['data']['issues']['nodes'] ?? [] as $node) {
            $issues[] = $this->normalizeIssue($node);
        }

        return $issues;
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
    {
        // $issueNumber is the Linear issue UUID.
        $result = $this->graphql('
            query GetIssue($id: String!) {
                issue(id: $id) {
                    id
                    title
                    description
                    url
                    state { name }
                    labels { nodes { name } }
                    comments {
                        nodes {
                            id
                            body
                            createdAt
                            user { name }
                        }
                    }
                }
            }
        ', ['id' => (string) $issueNumber]);

        return $this->normalizeIssue($result['data']['issue'] ?? []);
    }

    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array {
        $result = $this->graphql('
            mutation CreateComment($input: CommentCreateInput!) {
                commentCreate(input: $input) {
                    success
                    comment { id }
                }
            }
        ', [
            'input' => [
                'issueId' => (string) $issueNumber,
                'body' => $body,
            ],
        ]);

        return $result['data']['commentCreate'] ?? [];
    }

    public function closeIssue(string $owner, string $project, int|string $issueNumber): void
    {
        // Linear has no generic "closed" flag — move the issue to the first
        // completed-type workflow state of its team.
        $states = $this->graphql('
            query CompletedState($id: String!) {
                issue(id: $id) {
                    team {
                        states(filter: { type: { eq: "completed" } }) {
                            nodes { id }
                        }
                    }
                }
            }
        ', ['id' => (string) $issueNumber]);

        $stateId = $states['data']['issue']['team']['states']['nodes'][0]['id'] ?? null;
        if (! is_string($stateId) || $stateId === '') {
            throw new RuntimeException("No completed workflow state found for Linear issue: {$issueNumber}");
        }

        $this->graphql('
            mutation CloseIssue($id: String!, $stateId: String!) {
                issueUpdate(id: $id, input: { stateId: $stateId }) {
                    success
                }
            }
        ', ['id' => (string) $issueNumber, 'stateId' => $stateId]);
    }

    public function commentId(array $createResult): ?string
    {
        $id = $createResult['comment']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Best-effort against the Linear GraphQL schema (reactions on comments) —
     * verify live and adjust if the field shape differs. $owner/$project/$issueId
     * are unused: Linear ids are global.
     */
    public function getCommentReactions(string $owner, string $project, int|string $issueId, int|string $commentId): array
    {
        $result = $this->graphql('
            query CommentReactions($id: String!) {
                comment(id: $id) {
                    reactions { emoji user { id displayName } }
                }
            }
        ', ['id' => (string) $commentId]);

        $reactions = $result['data']['comment']['reactions'] ?? [];

        $out = [];
        foreach (is_array($reactions) ? $reactions : [] as $reaction) {
            if (! is_array($reaction)) {
                continue;
            }
            $out[] = [
                'emoji' => (string) ($reaction['emoji'] ?? ''),
                'user_id' => (string) ($reaction['user']['id'] ?? ''),
                'user_login' => (string) ($reaction['user']['displayName'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Linear has no per-repo permissions. "write/admin" maps to an active,
     * non-guest organisation member (admins are a non-guest superset). Tighten
     * to `admin` only if needed.
     */
    public function userCanApprove(string $owner, string $project, array $reactor): bool
    {
        $userId = $reactor['user_id'];
        if ($userId === '') {
            return false;
        }

        try {
            $result = $this->graphql('
                query User($id: String!) {
                    user(id: $id) { active admin guest }
                }
            ', ['id' => $userId]);
        } catch (\Throwable) {
            return false;
        }

        $user = $result['data']['user'] ?? null;
        if (! is_array($user)) {
            return false;
        }

        return ($user['active'] ?? false) === true && ($user['guest'] ?? false) !== true;
    }

    /**
     * Linear sends a raw HMAC-SHA256 hex digest in the Linear-Signature header (no prefix).
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function registerWebhook(string $owner, string $project, string $url, string $secret): array
    {
        $teamId = $this->resolveTeamId($owner);

        $result = $this->graphql('
            mutation CreateWebhook($input: WebhookCreateInput!) {
                webhookCreate(input: $input) {
                    success
                    webhook { id url }
                }
            }
        ', [
            'input' => [
                'url' => $url,
                'resourceTypes' => ['Issue'],
                'teamId' => $teamId,
                'secret' => $secret,
            ],
        ]);

        return $result['data']['webhookCreate']['webhook'] ?? [];
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        $this->graphql('
            mutation DeleteWebhook($id: String!) {
                webhookDelete(id: $id) {
                    success
                }
            }
        ', ['id' => (string) $webhookId]);
    }

    /**
     * Linear sends {type, action, data, organizationId, webhookTimestamp} envelopes.
     * Only "Issue" type events are ingested; comment and project events are ignored.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        if (($envelope['type'] ?? '') !== 'Issue') {
            return [];
        }

        $data = $envelope['data'] ?? [];
        if (empty($data)) {
            return [];
        }

        return $this->normalizeIssue($data);
    }

    /**
     * Resolve a Linear team key (e.g. 'ENG') to the team's internal UUID.
     */
    private function resolveTeamId(string $teamKey): string
    {
        $result = $this->graphql('
            query GetTeam($key: String!) {
                teams(filter: { key: { eq: $key } }) {
                    nodes { id key }
                }
            }
        ', ['key' => $teamKey]);

        $nodes = $result['data']['teams']['nodes'] ?? [];

        if (empty($nodes)) {
            throw new RuntimeException("Linear team not found for key: {$teamKey}");
        }

        return (string) $nodes[0]['id'];
    }

    /**
     * Map a raw Linear issue node to the canonical array format understood
     * by IssueIngestService: id, html_url, title, body, state, labels.
     *
     * Handles both API list/getIssue nodes (labels under 'labels.nodes')
     * and webhook data payloads (labels as flat array).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $data): array
    {
        $state = $data['state'] ?? [];
        $stateName = is_array($state) ? (string) ($state['name'] ?? '') : (string) $state;

        // Labels come as {nodes: [{name}]} from API queries, or [{name}] from webhooks.
        $rawLabels = $data['labels']['nodes'] ?? (is_array($data['labels'] ?? null) ? $data['labels'] : []);
        $labels = array_values(array_map(
            fn (array $l): array => ['name' => (string) ($l['name'] ?? '')],
            array_filter((array) $rawLabels, fn (mixed $l): bool => is_array($l)),
        ));

        return [
            'id' => (string) ($data['id'] ?? ''),
            'html_url' => (string) ($data['url'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['description'] ?? ''),
            'state' => $stateName,
            'labels' => $labels,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        return $this->connector
            ->send(new GraphQLRequest($query, $variables))
            ->throw()
            ->json();
    }
}
