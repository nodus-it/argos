<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\DTO;

use App\Enums\TaskProviderKind;
use App\Support\Dto;

/**
 * Inbound DTO for a single issue as reported by a provider (poll list, single
 * fetch, or normalized webhook payload). Absorbs the per-provider key drift so
 * the Argos-side ingest/filter/signature code works against one typed shape:
 *   - id addressed per-repo: GitHub `number`, GitLab `iid`, others `id`
 *   - body: `body` (GitHub/Bitbucket) vs `description` (GitLab)
 *   - url:  `html_url` (GitHub) vs `web_url` (GitLab)
 *   - state: `state` vs `status`
 *   - labels: a list of strings or of `{name: …}` objects
 */
final readonly class ExternalIssue extends Dto
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public string $body,
        public string $url,
        public string $state,
        public array $labels,
        // Human-readable key where the tracker has one (Linear: "ENG-118").
        public string $key = '',
        // Provider login/username of the assignee (Linear: e-mail address).
        public ?string $assignee = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  raw provider issue data
     */
    public static function fromProvider(array $payload, TaskProviderKind $kind): self
    {
        // The per-repo identifier issues are addressed by for API write-back
        // (comments, close); the provider's global id 404s those calls.
        $externalId = match ($kind) {
            TaskProviderKind::GitHub => $payload['number'] ?? $payload['id'] ?? null,
            TaskProviderKind::GitLab => $payload['iid'] ?? $payload['id'] ?? null,
            default => $payload['id'] ?? null,
        };

        return new self(
            externalId: (string) ($externalId ?? ''),
            title: (string) ($payload['title'] ?? ''),
            body: (string) ($payload['body'] ?? $payload['description'] ?? ''),
            url: (string) ($payload['html_url'] ?? $payload['web_url'] ?? ''),
            state: (string) ($payload['state'] ?? $payload['status'] ?? ''),
            labels: self::normalizeLabels($payload['labels'] ?? []),
            key: (string) ($payload['identifier'] ?? ''),
            assignee: self::normalizeAssignee($payload, $kind),
        );
    }

    /**
     * GitHub `assignee.login`, GitLab `assignee.username` (each falling back
     * to the first of `assignees`), Linear `assignee.email`, Bitbucket
     * `assignee.nickname`/`username`.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function normalizeAssignee(array $payload, TaskProviderKind $kind): ?string
    {
        $keys = match ($kind) {
            TaskProviderKind::GitHub => ['login'],
            TaskProviderKind::GitLab => ['username'],
            // Linear (email) and Bitbucket (nickname/username), which has no
            // TaskProviderKind case of its own.
            default => ['email', 'nickname', 'username', 'login'],
        };

        $candidates = [$payload['assignee'] ?? null];
        $assignees = $payload['assignees'] ?? null;
        if (is_array($assignees) && array_is_list($assignees)) {
            $candidates[] = $assignees[0] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = $candidate[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function normalizeLabels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $label): string => is_array($label) ? (string) ($label['name'] ?? '') : (string) $label,
            $raw,
        ));
    }
}
