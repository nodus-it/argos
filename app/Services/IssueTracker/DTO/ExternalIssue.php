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
        );
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
