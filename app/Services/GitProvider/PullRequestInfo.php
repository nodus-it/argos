<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Enums\GitProvider;

/**
 * Provider-neutral view of a pull/merge request API payload: GitHub
 * (`number`, `html_url`, `state` + `merged`), GitLab (`iid`, `web_url`,
 * `opened|merged|closed`), Bitbucket (`id`, `links.html.href`,
 * `OPEN|MERGED|DECLINED|SUPERSEDED`).
 */
final readonly class PullRequestInfo
{
    public function __construct(
        public ?int $number,
        public ?string $url,
        /** open | merged | closed */
        public ?string $state,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function from(GitProvider $platform, array $raw): self
    {
        return match ($platform) {
            GitProvider::GitHub => new self(
                isset($raw['number']) ? (int) $raw['number'] : null,
                is_string($raw['html_url'] ?? null) ? $raw['html_url'] : null,
                ($raw['merged'] ?? false) === true || isset($raw['merged_at']) && $raw['merged_at'] !== null
                    ? 'merged'
                    : (($raw['state'] ?? null) === 'open' ? 'open' : (isset($raw['state']) ? 'closed' : null)),
            ),
            GitProvider::GitLab => new self(
                isset($raw['iid']) ? (int) $raw['iid'] : null,
                is_string($raw['web_url'] ?? null) ? $raw['web_url'] : null,
                match ($raw['state'] ?? null) {
                    'opened', 'locked' => 'open',
                    'merged' => 'merged',
                    'closed' => 'closed',
                    default => null,
                },
            ),
            GitProvider::Bitbucket => new self(
                isset($raw['id']) ? (int) $raw['id'] : null,
                is_string($raw['links']['html']['href'] ?? null) ? $raw['links']['html']['href'] : null,
                match ($raw['state'] ?? null) {
                    'OPEN' => 'open',
                    'MERGED' => 'merged',
                    'DECLINED', 'SUPERSEDED' => 'closed',
                    default => null,
                },
            ),
        };
    }
}
