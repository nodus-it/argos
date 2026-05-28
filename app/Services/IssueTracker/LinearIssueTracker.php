<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LinearIssueTracker implements IssueTrackerContract
{
    private const GRAPHQL_URL = 'https://api.linear.app/graphql';

    public function __construct(private readonly string $token) {}

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
        $payload = ['query' => $query];

        // Linear rejects `variables: []` (an empty PHP array encodes to a JSON
        // array, not an object) — omit the key entirely for variable-less queries.
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        return $this->http()
            ->post(self::GRAPHQL_URL, $payload)
            ->throw()
            ->json();
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Content-Type' => 'application/json',
        ]);
    }
}
