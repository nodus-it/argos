<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Integrations\GitHub\GitHubConnector;
use App\Integrations\GitHub\Requests\CommentOnPullRequest;
use App\Integrations\GitHub\Requests\CreatePullRequest;
use App\Integrations\GitHub\Requests\GetActionsJobLogs;
use App\Integrations\GitHub\Requests\GetCombinedStatus;
use App\Integrations\GitHub\Requests\GetFileContents;
use App\Integrations\GitHub\Requests\GetIssueComments;
use App\Integrations\GitHub\Requests\GetPullRequest;
use App\Integrations\GitHub\Requests\GetRepository;
use App\Integrations\GitHub\Requests\GraphQL;
use App\Integrations\GitHub\Requests\ListBranches;
use App\Integrations\GitHub\Requests\ListCheckRunAnnotations;
use App\Integrations\GitHub\Requests\ListCheckRuns;
use App\Integrations\GitHub\Requests\ListPullRequestReviewComments;
use App\Integrations\GitHub\Requests\ListPullRequestReviews;
use App\Integrations\GitHub\Requests\ListRepositories;
use App\Integrations\GitHub\Requests\UpdatePullRequest;
use App\Services\GitProvider\Concerns\SummarizesPullRequests;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GitHubGitService implements GitProviderContract
{
    use SummarizesPullRequests;

    /** Check-run conclusions that count as a failed check. */
    private const array FAILED_CONCLUSIONS = ['failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure'];

    private readonly GitHubConnector $connector;

    public function __construct(string $token)
    {
        $this->connector = new GitHubConnector($token);
    }

    public function getProviderKey(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function listRepositories(): array
    {
        return $this->connector->send(new ListRepositories)->throw()->json();
    }

    public function listBranches(string $owner, string $repo): array
    {
        return $this->connector->send(new ListBranches($owner, $repo))->throw()->json();
    }

    /**
     * Fetch repository metadata. Used to pre-select the API-reported
     * default_branch when the user picks a repo in the form.
     *
     * @return array<string, mixed>
     */
    public function getRepository(string $owner, string $repo): array
    {
        return $this->connector->send(new GetRepository($owner, $repo))->throw()->json();
    }

    /**
     * Returns the API-reported default branch of the given owner/repo,
     * or null if the call fails (network/auth/not-found).
     */
    public function getDefaultBranch(string $ownerRepo): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $data = $this->getRepository($owner, $repo);
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitHub getDefaultBranch failed', [
                'owner_repo' => $ownerRepo,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        $branch = $data['default_branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    public function getFileContents(string $ownerRepo, string $path, string $ref): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $response = $this->connector->send(new GetFileContents($owner, $repo, $path, $ref));

            if ($response->status() === 404) {
                return null;
            }

            $data = $response->throw()->json();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitHub getFileContents failed', [
                'owner_repo' => $ownerRepo,
                'path' => $path,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        $content = $data['content'] ?? null;
        if (! is_string($content)) {
            return null;
        }

        $decoded = base64_decode(str_replace("\n", '', $content), true);

        return $decoded === false ? null : $decoded;
    }

    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $body,
        string $headBranch,
        string $baseBranch,
        array $options = [],
    ): array {
        return $this->connector
            ->send(new CreatePullRequest($owner, $repo, $title, $body, $headBranch, $baseBranch, $options))
            ->throw()
            ->json();
    }

    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array {
        return $this->connector
            ->send(new CommentOnPullRequest($owner, $repo, $pullRequestId, $body))
            ->throw()
            ->json();
    }

    public function updatePullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $title,
        string $body,
    ): array {
        return $this->connector
            ->send(new UpdatePullRequest($owner, $repo, $pullRequestId, $title, $body))
            ->throw()
            ->json();
    }

    /**
     * Returns repos as ['owner/repo' => 'owner/repo'] for use in Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getRepoOptions(): array
    {
        $repos = $this->listRepositories();

        $options = [];
        foreach ($repos as $repo) {
            $fullName = $repo['full_name'] ?? null;
            if (is_string($fullName) && $fullName !== '') {
                $options[$fullName] = $fullName;
            }
        }

        return $options;
    }

    /**
     * Returns branches as ['branch' => 'branch'] for use in Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getBranchOptions(string $ownerRepo): array
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return [];
        }

        $branches = $this->listBranches($owner, $repo);

        $options = [];
        foreach ($branches as $branch) {
            $name = $branch['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $options[$name] = $name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPullRequest(string $owner, string $repo, int|string $pullRequestId): array
    {
        return $this->connector
            ->send(new GetPullRequest($owner, $repo, $pullRequestId))
            ->throw()
            ->json();
    }

    public function pullRequestDetails(string $owner, string $repo, int|string $number): PullRequestDetails
    {
        $pr = $this->getPullRequest($owner, $repo, $number);
        $url = $this->stringOrNull($pr['html_url'] ?? null);
        $headSha = $this->stringOrNull($pr['head']['sha'] ?? null);
        $state = $this->pullRequestState($pr);
        $mergeableState = $pr['mergeable_state'] ?? null;

        /** @var list<array<string, mixed>> $rawReviews */
        $rawReviews = $this->optionalFetch('reviews', fn (): mixed => $this->connector->send(new ListPullRequestReviews($owner, $repo, $number))->throw()->json());

        return new PullRequestDetails(
            state: $state,
            url: $url,
            headSha: $headSha,
            conflicts: in_array($state, ['open', 'draft'], true)
                && (($pr['mergeable'] ?? null) === false || $mergeableState === 'dirty'),
            behindBase: match ($mergeableState) {
                'behind' => true,
                'clean', 'unstable', 'has_hooks' => false,
                default => null,
            },
            checks: $headSha === null ? ['state' => 'none', 'url' => null, 'failed' => []] : $this->checks($owner, $repo, $headSha, $url),
            reviewState: $this->reviewState($rawReviews),
            reviews: $this->reviews($rawReviews),
            comments: $this->comments($owner, $repo, $number),
            autoMerge: is_array($pr['auto_merge'] ?? null),
        );
    }

    public function markPullRequestReady(string $owner, string $repo, int|string $number): void
    {
        $pr = $this->getPullRequest($owner, $repo, $number);

        if (($pr['draft'] ?? false) !== true) {
            return;
        }

        $result = $this->graphql(
            'mutation($id: ID!) { markPullRequestReadyForReview(input: {pullRequestId: $id}) { pullRequest { isDraft } } }',
            ['id' => $this->nodeId($pr)],
        );

        if ($result['errors'] !== []) {
            throw new RuntimeException('GitHub could not mark the pull request ready for review: '.implode('; ', $result['errors']));
        }
    }

    public function enableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        $pr = $this->getPullRequest($owner, $repo, $number);

        if (is_array($pr['auto_merge'] ?? null)) {
            return true;
        }

        if (! in_array($this->pullRequestState($pr), ['open', 'draft'], true)) {
            $this->logAutoMerge('GitHub auto-merge skipped: pull request is not open', $owner, $repo, $number);

            return false;
        }

        /** @var array<string, mixed> $repository */
        $repository = $this->optionalFetch('repository', fn (): mixed => $this->getRepository($owner, $repo));

        if (($repository['allow_auto_merge'] ?? null) === false) {
            $this->logAutoMerge('GitHub auto-merge not allowed for repository', $owner, $repo, $number);

            return false;
        }

        $variables = ['id' => $this->nodeId($pr)];
        $method = $this->mergeMethod($repository);
        if ($method !== null) {
            $variables['method'] = $method;
        }

        $result = $this->graphql(
            $method === null
                ? 'mutation($id: ID!) { enablePullRequestAutoMerge(input: {pullRequestId: $id}) { pullRequest { autoMergeRequest { enabledAt } } } }'
                : 'mutation($id: ID!, $method: PullRequestMergeMethod!) { enablePullRequestAutoMerge(input: {pullRequestId: $id, mergeMethod: $method}) { pullRequest { autoMergeRequest { enabledAt } } } }',
            $variables,
        );

        if ($result['errors'] !== []) {
            // e.g. "Auto merge is not allowed for this repository", "Pull request is in clean status".
            $this->logAutoMerge('GitHub auto-merge not enabled', $owner, $repo, $number, $result['errors']);

            return false;
        }

        return is_array($result['data']['enablePullRequestAutoMerge']['pullRequest']['autoMergeRequest'] ?? null);
    }

    public function disableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        $pr = $this->getPullRequest($owner, $repo, $number);

        if (! is_array($pr['auto_merge'] ?? null)) {
            return true;
        }

        $result = $this->graphql(
            'mutation($id: ID!) { disablePullRequestAutoMerge(input: {pullRequestId: $id}) { pullRequest { autoMergeRequest { enabledAt } } } }',
            ['id' => $this->nodeId($pr)],
        );

        if ($result['errors'] !== []) {
            $this->logAutoMerge('GitHub auto-merge not disabled', $owner, $repo, $number, $result['errors']);

            return false;
        }

        return true;
    }

    public function failedCheckLog(string $owner, string $repo, int|string $number): string
    {
        $pr = $this->getPullRequest($owner, $repo, $number);
        $headSha = $this->stringOrNull($pr['head']['sha'] ?? null);

        if ($headSha === null) {
            return '';
        }

        $sections = [];

        foreach (array_slice($this->failedCheckRuns($owner, $repo, $headSha), 0, 10) as $run) {
            $lines = ['## '.($this->stringOrNull($run['name'] ?? null) ?? 'check').' ('.($run['conclusion'] ?? 'failed').')'];
            if (($detailsUrl = $this->stringOrNull($run['details_url'] ?? $run['html_url'] ?? null)) !== null) {
                $lines[] = $detailsUrl;
            }

            foreach (['title', 'summary', 'text'] as $field) {
                if (($text = $this->stringOrNull($run['output'][$field] ?? null)) !== null) {
                    $lines[] = $this->tail($text, self::CHECK_SECTION_LIMIT);
                }
            }

            if ((int) ($run['output']['annotations_count'] ?? 0) > 0 && isset($run['id'])) {
                $annotations = $this->annotations($owner, $repo, $run['id']);
                if ($annotations !== []) {
                    $lines[] = "Annotations:\n".implode("\n", $annotations);
                }
            }

            if (($run['app']['slug'] ?? null) === 'github-actions' && isset($run['id'])) {
                $log = $this->actionsJobLog($owner, $repo, $run['id']);
                if ($log !== '') {
                    $lines[] = "Log (tail):\n".$log;
                }
            }

            $sections[] = implode("\n", $lines);
        }

        foreach ($this->failedStatuses($owner, $repo, $headSha) as $status) {
            $sections[] = implode("\n", array_filter([
                '## '.($this->stringOrNull($status['context'] ?? null) ?? 'status').' ('.($status['state'] ?? 'failure').')',
                $this->stringOrNull($status['description'] ?? null),
                $this->stringOrNull($status['target_url'] ?? null),
            ]));
        }

        return $sections === [] ? '' : $this->tail(implode("\n\n", $sections));
    }

    /**
     * @param  array<string, mixed>  $pr
     * @return 'draft'|'open'|'merged'|'closed'
     */
    private function pullRequestState(array $pr): string
    {
        return match (true) {
            ($pr['merged'] ?? false) === true || ($pr['merged_at'] ?? null) !== null => 'merged',
            ($pr['state'] ?? null) !== 'open' => 'closed',
            ($pr['draft'] ?? false) === true => 'draft',
            default => 'open',
        };
    }

    /**
     * @return array{state: 'pending'|'success'|'failure'|'none', url: ?string, failed: list<array{name: string, url: ?string}>}
     */
    private function checks(string $owner, string $repo, string $sha, ?string $prUrl): array
    {
        /** @var list<array<string, mixed>> $runs */
        $runs = $this->optionalFetch('check runs', fn (): mixed => $this->connector->send(new ListCheckRuns($owner, $repo, $sha))->throw()->json('check_runs', []));
        /** @var list<array<string, mixed>> $statuses */
        $statuses = $this->optionalFetch('commit status', fn (): mixed => $this->connector->send(new GetCombinedStatus($owner, $repo, $sha))->throw()->json('statuses', []));

        $states = [];
        $failed = [];

        foreach ($runs as $run) {
            $state = match (true) {
                ($run['status'] ?? null) !== 'completed' => 'pending',
                in_array($run['conclusion'] ?? null, self::FAILED_CONCLUSIONS, true) => 'failure',
                default => 'success',
            };
            $states[] = $state;

            if ($state === 'failure') {
                $failed[] = ['name' => (string) ($run['name'] ?? 'check'), 'url' => $this->stringOrNull($run['details_url'] ?? $run['html_url'] ?? null)];
            }
        }

        foreach ($statuses as $status) {
            $state = match ($status['state'] ?? null) {
                'success' => 'success',
                'failure', 'error' => 'failure',
                default => 'pending',
            };
            $states[] = $state;

            if ($state === 'failure') {
                $failed[] = ['name' => (string) ($status['context'] ?? 'status'), 'url' => $this->stringOrNull($status['target_url'] ?? null)];
            }
        }

        return [
            'state' => $this->summarizeCheckStates($states),
            'url' => $failed[0]['url'] ?? ($states !== [] && $prUrl !== null ? $prUrl.'/checks' : null),
            'failed' => $failed,
        ];
    }

    /**
     * Latest decisive review per reviewer: approvals and change requests stick
     * until superseded or dismissed; a plain comment never overrides them.
     *
     * @param  list<array<string, mixed>>  $reviews
     * @return 'approved'|'changes_requested'|'commented'|null
     */
    private function reviewState(array $reviews): ?string
    {
        $byReviewer = [];

        foreach ($reviews as $review) {
            $login = (string) ($review['user']['login'] ?? '');

            match ($review['state'] ?? null) {
                'APPROVED' => $byReviewer[$login] = 'approved',
                'CHANGES_REQUESTED' => $byReviewer[$login] = 'changes_requested',
                'COMMENTED' => $byReviewer[$login] ??= 'commented',
                'DISMISSED' => $byReviewer[$login] = 'dismissed',
                default => null,
            };
        }

        return $this->summarizeReviewStates(array_values($byReviewer));
    }

    /**
     * @param  list<array<string, mixed>>  $reviews
     * @return list<array{author: string, state: string, body: string, submitted_at: ?string}>
     */
    private function reviews(array $reviews): array
    {
        $out = [];

        foreach ($reviews as $review) {
            $state = $review['state'] ?? null;
            if (! is_string($state) || $state === 'PENDING') {
                continue;
            }

            $out[] = [
                'author' => (string) ($review['user']['login'] ?? ''),
                'state' => strtolower($state),
                'body' => (string) ($review['body'] ?? ''),
                'submitted_at' => $this->stringOrNull($review['submitted_at'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, author: string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}>
     */
    private function comments(string $owner, string $repo, int|string $number): array
    {
        /** @var list<array<string, mixed>> $reviewComments */
        $reviewComments = $this->optionalFetch('review comments', fn (): mixed => $this->connector->send(new ListPullRequestReviewComments($owner, $repo, $number))->throw()->json());
        /** @var list<array<string, mixed>> $issueComments */
        $issueComments = $this->optionalFetch('issue comments', fn (): mixed => $this->connector->send(new GetIssueComments($owner, $repo, $number))->throw()->json());

        $comments = [];

        foreach ($reviewComments as $comment) {
            $comments[] = [
                'id' => (string) ($comment['id'] ?? ''),
                'author' => (string) ($comment['user']['login'] ?? ''),
                'body' => (string) ($comment['body'] ?? ''),
                'path' => $this->stringOrNull($comment['path'] ?? null),
                'line' => $this->intOrNull($comment['line'] ?? $comment['original_line'] ?? null),
                'url' => $this->stringOrNull($comment['html_url'] ?? null),
                'created_at' => $this->stringOrNull($comment['created_at'] ?? null),
            ];
        }

        foreach ($issueComments as $comment) {
            $comments[] = [
                'id' => (string) ($comment['id'] ?? ''),
                'author' => (string) ($comment['user']['login'] ?? ''),
                'body' => (string) ($comment['body'] ?? ''),
                'path' => null,
                'line' => null,
                'url' => $this->stringOrNull($comment['html_url'] ?? null),
                'created_at' => $this->stringOrNull($comment['created_at'] ?? null),
            ];
        }

        return $this->sortComments($comments);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failedCheckRuns(string $owner, string $repo, string $sha): array
    {
        /** @var list<array<string, mixed>> $runs */
        $runs = $this->optionalFetch('check runs', fn (): mixed => $this->connector->send(new ListCheckRuns($owner, $repo, $sha))->throw()->json('check_runs', []));

        return array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['status'] ?? null) === 'completed' && in_array($run['conclusion'] ?? null, self::FAILED_CONCLUSIONS, true),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failedStatuses(string $owner, string $repo, string $sha): array
    {
        /** @var list<array<string, mixed>> $statuses */
        $statuses = $this->optionalFetch('commit status', fn (): mixed => $this->connector->send(new GetCombinedStatus($owner, $repo, $sha))->throw()->json('statuses', []));

        return array_values(array_filter($statuses, fn (array $status): bool => in_array($status['state'] ?? null, ['failure', 'error'], true)));
    }

    /**
     * @return list<string> "path:line [level] message"
     */
    private function annotations(string $owner, string $repo, int|string $checkRunId): array
    {
        /** @var list<array<string, mixed>> $annotations */
        $annotations = $this->optionalFetch('annotations', fn (): mixed => $this->connector->send(new ListCheckRunAnnotations($owner, $repo, $checkRunId))->throw()->json());

        $lines = [];
        foreach ($annotations as $annotation) {
            $location = ($annotation['path'] ?? '?').(isset($annotation['start_line']) ? ':'.$annotation['start_line'] : '');
            $message = trim((string) ($annotation['message'] ?? ''));
            $lines[] = "- {$location} [".($annotation['annotation_level'] ?? 'failure')."] {$message}";
        }

        return $lines;
    }

    /** Tail of a GitHub Actions job log; empty when unavailable (no permission, expired). */
    private function actionsJobLog(string $owner, string $repo, int|string $jobId): string
    {
        try {
            $response = $this->connector->send(new GetActionsJobLogs($owner, $repo, $jobId));

            if (! $response->successful()) {
                return '';
            }

            return $this->tail($this->cleanLog($response->body()), self::CHECK_SECTION_LIMIT);
        } catch (\Throwable $e) {
            Log::channel('argos')->info('GitHub job log unavailable', ['owner_repo' => "{$owner}/{$repo}", 'job' => $jobId, 'class' => $e::class]);

            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    private function graphql(string $query, array $variables): array
    {
        $json = $this->connector->send(new GraphQL($query, $variables))->throw()->json();

        $errors = [];
        foreach (is_array($json['errors'] ?? null) ? $json['errors'] : [] as $error) {
            $errors[] = is_array($error) ? (string) ($error['message'] ?? 'unknown error') : (string) $error;
        }

        return ['data' => is_array($json['data'] ?? null) ? $json['data'] : [], 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $pr
     */
    private function nodeId(array $pr): string
    {
        return $this->stringOrNull($pr['node_id'] ?? null)
            ?? throw new RuntimeException('GitHub pull request payload has no node_id.');
    }

    /**
     * GitHub's own default (merge commit) when allowed, else the one the
     * repository permits; null when the token cannot see the settings.
     *
     * @param  array<string, mixed>  $repository
     */
    private function mergeMethod(array $repository): ?string
    {
        return match (true) {
            ($repository['allow_merge_commit'] ?? null) === true => 'MERGE',
            ($repository['allow_squash_merge'] ?? null) === true => 'SQUASH',
            ($repository['allow_rebase_merge'] ?? null) === true => 'REBASE',
            default => null,
        };
    }

    /**
     * @param  list<string>  $errors
     */
    private function logAutoMerge(string $message, string $owner, string $repo, int|string $number, array $errors = []): void
    {
        Log::channel('argos')->info($message, array_filter([
            'owner_repo' => "{$owner}/{$repo}",
            'number' => $number,
            'errors' => $errors,
        ]));
    }
}
