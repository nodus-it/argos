<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Integrations\Bitbucket\BitbucketConnector;
use App\Integrations\Bitbucket\Requests\CommentOnPullRequest;
use App\Integrations\Bitbucket\Requests\CreatePullRequest;
use App\Integrations\Bitbucket\Requests\GetPullRequest;
use App\Integrations\Bitbucket\Requests\GetPullRequestDiffstat;
use App\Integrations\Bitbucket\Requests\GetRepository;
use App\Integrations\Bitbucket\Requests\GetSourceFile;
use App\Integrations\Bitbucket\Requests\ListBranches;
use App\Integrations\Bitbucket\Requests\ListPullRequestComments;
use App\Integrations\Bitbucket\Requests\ListPullRequestStatuses;
use App\Integrations\Bitbucket\Requests\ListUserWorkspaces;
use App\Integrations\Bitbucket\Requests\ListWorkspaceRepositories;
use App\Integrations\Bitbucket\Requests\UpdatePullRequest;
use App\Integrations\Bitbucket\Requests\UpdatePullRequestAttributes;
use App\Services\GitProvider\Concerns\SummarizesPullRequests;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BitbucketGitService implements GitProviderContract
{
    use SummarizesPullRequests;

    private readonly BitbucketConnector $connector;

    public function __construct(string $token)
    {
        // Auth (Basic vs. Bearer) is resolved by the connector from the token shape.
        $this->connector = new BitbucketConnector($token);
    }

    public function getProviderKey(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function listRepositories(): array
    {
        // Atlassian's CHANGE-2770 deprecation walked through three endpoints:
        // /repositories, /user/permissions/workspaces, and /workspaces are all
        // gone. /2.0/user/workspaces is the surviving (and explicitly announced)
        // replacement — values are workspace_access records with the slug
        // nested under .workspace.slug.
        $workspaces = $this->connector->send(new ListUserWorkspaces)->throw()->json('values', []);

        $repos = [];
        foreach ($workspaces as $access) {
            $slug = $access['workspace']['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $values = $this->connector
                ->send(new ListWorkspaceRepositories($slug))
                ->throw()
                ->json('values', []);

            foreach ($values as $repo) {
                $repos[] = $repo;
            }
        }

        return $repos;
    }

    public function listBranches(string $owner, string $repo): array
    {
        return $this->connector->send(new ListBranches($owner, $repo))->throw()->json('values', []);
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
     * Returns repos as ['workspace/slug' => 'workspace/slug'] for Filament Select dropdowns.
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
     * Returns branches as ['branch' => 'branch'] for Filament Select dropdowns.
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
     * Returns the default branch for a workspace/repo string, or null on failure.
     */
    public function getDefaultBranch(string $ownerRepo): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $data = $this->connector->send(new GetRepository($owner, $repo))->throw()->json();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('Bitbucket getDefaultBranch failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        $branch = $data['mainbranch']['name'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    public function getFileContents(string $ownerRepo, string $path, string $ref): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $response = $this->connector->send(new GetSourceFile($owner, $repo, $path, $ref));

            if ($response->status() === 404) {
                return null;
            }

            return $response->throw()->body();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('Bitbucket getFileContents failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'path' => $path,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }
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
        $state = $this->pullRequestState($pr);
        $comments = $this->comments($owner, $repo, $number);
        $reviews = $this->reviews($pr);

        return new PullRequestDetails(
            state: $state,
            url: $this->stringOrNull($pr['links']['html']['href'] ?? null),
            headSha: $this->stringOrNull($pr['source']['commit']['hash'] ?? null),
            conflicts: in_array($state, ['open', 'draft'], true) && $this->hasConflicts($owner, $repo, $number),
            // Bitbucket does not report whether the destination moved on.
            behindBase: null,
            checks: $this->checks($owner, $repo, $number),
            reviewState: $this->summarizeReviewStates(array_column($reviews, 'state')) ?? ($comments !== [] ? 'commented' : null),
            reviews: $reviews,
            comments: $comments,
            // Bitbucket Cloud has no native auto-merge.
            autoMerge: false,
        );
    }

    public function markPullRequestReady(string $owner, string $repo, int|string $number): void
    {
        $pr = $this->getPullRequest($owner, $repo, $number);

        if (($pr['draft'] ?? false) !== true) {
            return;
        }

        $response = $this->connector->send(new UpdatePullRequestAttributes($owner, $repo, $number, [
            'title' => (string) ($pr['title'] ?? ''),
            'draft' => false,
        ]));

        if ($response->serverError()) {
            $response->throw();
        }

        if (! $response->successful()) {
            $message = $response->json('error.message');

            throw new RuntimeException('Bitbucket did not accept taking the pull request out of draft (HTTP '.$response->status().')'.(is_string($message) ? ': '.$message : '.'));
        }

        if ($response->json('draft') === true) {
            throw new RuntimeException('Bitbucket does not support marking a draft pull request ready via the API — mark it ready in the Bitbucket UI.');
        }
    }

    public function enableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        Log::channel('argos')->info('Bitbucket auto-merge not supported', ['owner_repo' => "{$owner}/{$repo}", 'number' => $number]);

        return false;
    }

    public function disableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        Log::channel('argos')->info('Bitbucket auto-merge not supported', ['owner_repo' => "{$owner}/{$repo}", 'number' => $number]);

        return false;
    }

    public function failedCheckLog(string $owner, string $repo, int|string $number): string
    {
        $sections = [];

        foreach ($this->statuses($owner, $repo, $number) as $status) {
            if (! in_array($status['state'] ?? null, ['FAILED', 'STOPPED'], true)) {
                continue;
            }

            $sections[] = implode("\n", array_filter([
                '## '.($this->stringOrNull($status['name'] ?? null) ?? $this->stringOrNull($status['key'] ?? null) ?? 'build').' ('.strtolower((string) $status['state']).')',
                $this->stringOrNull($status['description'] ?? null),
                $this->stringOrNull($status['url'] ?? null),
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
        return match ($pr['state'] ?? null) {
            'MERGED' => 'merged',
            'OPEN' => ($pr['draft'] ?? false) === true ? 'draft' : 'open',
            default => 'closed',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statuses(string $owner, string $repo, int|string $number): array
    {
        /** @var list<array<string, mixed>> $statuses */
        $statuses = $this->optionalFetch('statuses', fn (): mixed => $this->connector->send(new ListPullRequestStatuses($owner, $repo, $number))->throw()->json('values', []));

        return $statuses;
    }

    /**
     * @return array{state: 'pending'|'success'|'failure'|'none', url: ?string, failed: list<array{name: string, url: ?string}>}
     */
    private function checks(string $owner, string $repo, int|string $number): array
    {
        $states = [];
        $failed = [];
        $firstUrl = null;

        foreach ($this->statuses($owner, $repo, $number) as $status) {
            $state = match ($status['state'] ?? null) {
                'SUCCESSFUL' => 'success',
                'FAILED', 'STOPPED' => 'failure',
                default => 'pending',
            };
            $states[] = $state;
            $url = $this->stringOrNull($status['url'] ?? null);
            $firstUrl ??= $url;

            if ($state === 'failure') {
                $failed[] = ['name' => (string) ($status['name'] ?? $status['key'] ?? 'build'), 'url' => $url];
            }
        }

        return [
            'state' => $this->summarizeCheckStates($states),
            'url' => $failed[0]['url'] ?? $firstUrl,
            'failed' => $failed,
        ];
    }

    private function hasConflicts(string $owner, string $repo, int|string $number): bool
    {
        /** @var list<array<string, mixed>> $files */
        $files = $this->optionalFetch('diffstat', fn (): mixed => $this->connector->send(new GetPullRequestDiffstat($owner, $repo, $number))->throw()->json('values', []));

        foreach ($files as $file) {
            if (is_string($file['status'] ?? null) && str_contains($file['status'], 'conflict')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Participants who approved or requested changes.
     *
     * @param  array<string, mixed>  $pr
     * @return list<array{author: string, avatar?: ?string, profile?: ?string, url?: ?string, state: string, body: string, submitted_at: ?string}>
     */
    private function reviews(array $pr): array
    {
        $reviews = [];

        foreach (is_array($pr['participants'] ?? null) ? $pr['participants'] : [] as $participant) {
            if (! is_array($participant)) {
                continue;
            }

            $state = match (true) {
                ($participant['state'] ?? null) === 'changes_requested' => 'changes_requested',
                ($participant['approved'] ?? false) === true, ($participant['state'] ?? null) === 'approved' => 'approved',
                default => null,
            };

            if ($state === null) {
                continue;
            }

            $reviews[] = [
                'author' => $this->author($participant['user'] ?? null),
                'avatar' => $this->stringOrNull($participant['user']['links']['avatar']['href'] ?? null),
                'profile' => $this->stringOrNull($participant['user']['links']['html']['href'] ?? null),
                'state' => $state,
                'body' => '',
                'submitted_at' => $this->stringOrNull($participant['participated_on'] ?? null),
            ];
        }

        return $reviews;
    }

    /**
     * @return list<array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}>
     */
    private function comments(string $owner, string $repo, int|string $number): array
    {
        /** @var list<array<string, mixed>> $values */
        $values = $this->optionalFetch('comments', fn (): mixed => $this->connector->send(new ListPullRequestComments($owner, $repo, $number))->throw()->json('values', []));

        $comments = [];

        foreach ($values as $comment) {
            if (($comment['deleted'] ?? false) === true) {
                continue;
            }

            $inline = is_array($comment['inline'] ?? null) ? $comment['inline'] : [];

            $comments[] = [
                'id' => (string) ($comment['id'] ?? ''),
                'author' => $this->author($comment['user'] ?? null),
                'avatar' => $this->stringOrNull($comment['user']['links']['avatar']['href'] ?? null),
                'profile' => $this->stringOrNull($comment['user']['links']['html']['href'] ?? null),
                'body' => (string) ($comment['content']['raw'] ?? ''),
                'path' => $this->stringOrNull($inline['path'] ?? null),
                'line' => $this->intOrNull($inline['to'] ?? $inline['from'] ?? null),
                'url' => $this->stringOrNull($comment['links']['html']['href'] ?? null),
                'created_at' => $this->stringOrNull($comment['created_on'] ?? null),
            ];
        }

        return $this->sortComments($comments);
    }

    private function author(mixed $user): string
    {
        if (! is_array($user)) {
            return '';
        }

        return (string) ($user['nickname'] ?? $user['display_name'] ?? $user['username'] ?? '');
    }
}
