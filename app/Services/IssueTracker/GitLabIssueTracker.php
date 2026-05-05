<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitLabIssueTracker implements IssueTrackerContract
{
    public function __construct(
        private readonly string $token,
        private readonly string $instanceUrl = 'https://gitlab.com',
    ) {}

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        $projectPath = $this->encodePath($owner, $project);

        return $this->http()
            ->get("/projects/{$projectPath}/issues", ['per_page' => 100, ...$filters])
            ->throw()
            ->json();
    }

    public function getIssue(string $owner, string $project, int $issueNumber): array
    {
        $projectPath = $this->encodePath($owner, $project);
        $base = "/projects/{$projectPath}/issues/{$issueNumber}";

        $issue = $this->http()->get($base)->throw()->json();
        $notes = $this->http()->get("{$base}/notes", ['per_page' => 100])->throw()->json();

        // Award emojis require a GitLab plan that supports them — treat 404/403 as empty.
        $awardEmojis = rescue(
            fn () => $this->http()->get("{$base}/award_emoji", ['per_page' => 100])->throw()->json(),
            [],
        );

        return [
            ...$issue,
            'comments_data' => $notes,
            'reactions_data' => $awardEmojis,
        ];
    }

    public function createIssue(
        string $owner,
        string $project,
        string $title,
        string $body,
        array $options = [],
    ): array {
        $projectPath = $this->encodePath($owner, $project);

        return $this->http()
            ->post("/projects/{$projectPath}/issues", [
                'title' => $title,
                'description' => $body,
                ...$options,
            ])
            ->throw()
            ->json();
    }

    public function createComment(
        string $owner,
        string $project,
        int $issueNumber,
        string $body,
    ): array {
        $projectPath = $this->encodePath($owner, $project);

        return $this->http()
            ->post("/projects/{$projectPath}/issues/{$issueNumber}/notes", ['body' => $body])
            ->throw()
            ->json();
    }

    private function encodePath(string $owner, string $project): string
    {
        return urlencode("{$owner}/{$project}");
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Content-Type' => 'application/json',
        ])->baseUrl("{$this->instanceUrl}/api/v4");
    }
}
