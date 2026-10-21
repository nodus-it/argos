<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Integrations\GitLab\GitLabConnector;
use App\Integrations\GitLab\Requests\CancelMergeWhenPipelineSucceeds;
use App\Integrations\GitLab\Requests\CommentOnMergeRequest;
use App\Integrations\GitLab\Requests\CreateMergeRequest;
use App\Integrations\GitLab\Requests\GetJobTrace;
use App\Integrations\GitLab\Requests\GetMergeRequest;
use App\Integrations\GitLab\Requests\GetMergeRequestApprovals;
use App\Integrations\GitLab\Requests\GetProject;
use App\Integrations\GitLab\Requests\GetRawFile;
use App\Integrations\GitLab\Requests\ListBranches;
use App\Integrations\GitLab\Requests\ListFailedPipelineJobs;
use App\Integrations\GitLab\Requests\ListMergeRequestDiscussions;
use App\Integrations\GitLab\Requests\ListProjects;
use App\Integrations\GitLab\Requests\MergeWhenPipelineSucceeds;
use App\Integrations\GitLab\Requests\UpdateMergeRequest;
use App\Integrations\GitLab\Requests\UpdateMergeRequestAttributes;
use App\Services\GitProvider\Concerns\SummarizesPullRequests;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GitLabGitService implements GitProviderContract
{
    use SummarizesPullRequests;

    /** Pipeline statuses that are still on their way to a result. */
    private const array RUNNING_PIPELINE_STATUSES = ['created', 'waiting_for_resource', 'preparing', 'pending', 'running', 'scheduled'];

    /** Title prefixes GitLab treats as the draft marker. */
    private const string DRAFT_PREFIX = '/^\s*(\[draft\]|\(draft\)|draft:|draft\s+-|\[wip\]|wip:)\s*/i';

    private readonly GitLabConnector $connector;

    public function __construct(string $token, string $instanceUrl = 'https://gitlab.com')
    {
        $this->connector = new GitLabConnector($token, $instanceUrl);
    }

    public function getProviderKey(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function listRepositories(): array
    {
        return $this->connector->send(new ListProjects)->throw()->json();
    }

    public function listBranches(string $owner, string $repo): array
    {
        return $this->connector->send(new ListBranches($owner, $repo))->throw()->json();
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
            $data = $this->connector->send(new GetProject($owner, $repo))->throw()->json();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitLab getDefaultBranch failed', [
                'owner_repo' => "{$owner}/{$repo}",
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
            $response = $this->connector->send(new GetRawFile($owner, $repo, $path, $ref));

            if ($response->status() === 404) {
                return null;
            }

            return $response->throw()->body();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitLab getFileContents failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'path' => $path,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }
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
            ->send(new CreateMergeRequest($owner, $repo, $title, $body, $headBranch, $baseBranch, $options))
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
            ->send(new CommentOnMergeRequest($owner, $repo, $pullRequestId, $body))
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
            ->send(new UpdateMergeRequest($owner, $repo, $pullRequestId, $title, $body))
            ->throw()
            ->json();
    }

    /**
     * Returns projects as ['namespace/name' => 'namespace/name'] for use in Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getRepoOptions(): array
    {
        $projects = $this->listRepositories();

        $options = [];
        foreach ($projects as $project) {
            $path = $project['path_with_namespace'] ?? null;
            if (is_string($path) && $path !== '') {
                $options[$path] = $path;
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
            ->send(new GetMergeRequest($owner, $repo, $pullRequestId))
            ->throw()
            ->json();
    }

    public function pullRequestDetails(string $owner, string $repo, int|string $number): PullRequestDetails
    {
        $mr = $this->connector->send(new GetMergeRequest($owner, $repo, $number, includeDivergedCommitsCount: true))->throw()->json();
        $url = $this->stringOrNull($mr['web_url'] ?? null);
        $state = $this->mergeRequestState($mr);
        $diverged = $mr['diverged_commits_count'] ?? null;

        /** @var list<array<string, mixed>> $discussions */
        $discussions = $this->optionalFetch('discussions', fn (): mixed => $this->connector->send(new ListMergeRequestDiscussions($owner, $repo, $number))->throw()->json());
        /** @var array<string, mixed> $approvals */
        $approvals = $this->optionalFetch('approvals', fn (): mixed => $this->connector->send(new GetMergeRequestApprovals($owner, $repo, $number))->throw()->json());

        $comments = $this->comments($discussions, $url);
        $approvedBy = $this->approvedBy($approvals);

        return new PullRequestDetails(
            state: $state,
            url: $url,
            headSha: $this->stringOrNull($mr['sha'] ?? null),
            conflicts: in_array($state, ['open', 'draft'], true)
                && (($mr['has_conflicts'] ?? false) === true || ($mr['detailed_merge_status'] ?? null) === 'conflict'),
            behindBase: match (true) {
                is_int($diverged) => $diverged > 0,
                ($mr['detailed_merge_status'] ?? null) === 'need_rebase' => true,
                default => null,
            },
            checks: $this->checks($owner, $repo, $mr),
            reviewState: match (true) {
                $this->hasUnresolvedThreads($discussions) => 'changes_requested',
                $approvedBy !== [] => 'approved',
                $comments !== [] => 'commented',
                default => null,
            },
            reviews: array_map(fn (string $user): array => ['author' => $user, 'state' => 'approved', 'body' => '', 'submitted_at' => null], $approvedBy),
            comments: $comments,
            autoMerge: ($mr['merge_when_pipeline_succeeds'] ?? false) === true,
        );
    }

    public function markPullRequestReady(string $owner, string $repo, int|string $number): void
    {
        $mr = $this->getPullRequest($owner, $repo, $number);

        if ($this->mergeRequestState($mr) !== 'draft') {
            return;
        }

        // The MR API has no `draft` attribute to write — GitLab derives the
        // draft flag from the title prefix, so removing it is what "ready" is.
        $title = (string) ($mr['title'] ?? '');
        do {
            $previous = $title;
            $title = (string) preg_replace(self::DRAFT_PREFIX, '', $title);
        } while ($title !== $previous);

        $updated = $this->connector
            ->send(new UpdateMergeRequestAttributes($owner, $repo, $number, ['title' => $title === '' ? $previous : $title]))
            ->throw()
            ->json();

        if (($updated['draft'] ?? $updated['work_in_progress'] ?? false) === true) {
            throw new RuntimeException('GitLab still reports the merge request as draft after removing the draft prefix from its title.');
        }
    }

    public function enableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        $mr = $this->getPullRequest($owner, $repo, $number);

        if (($mr['merge_when_pipeline_succeeds'] ?? false) === true) {
            return true;
        }

        if ($this->mergeRequestState($mr) !== 'open') {
            $this->logAutoMerge('GitLab auto-merge skipped: merge request is not open and ready', $owner, $repo, $number);

            return false;
        }

        // Without a pipeline still running, the merge endpoint merges right
        // away instead of arming auto-merge — never do that implicitly.
        if (! in_array($mr['head_pipeline']['status'] ?? null, self::RUNNING_PIPELINE_STATUSES, true)) {
            $this->logAutoMerge('GitLab auto-merge skipped: no running head pipeline', $owner, $repo, $number);

            return false;
        }

        $response = $this->connector->send(new MergeWhenPipelineSucceeds($owner, $repo, $number, $this->stringOrNull($mr['sha'] ?? null)));

        if ($response->serverError()) {
            $response->throw();
        }

        if (! $response->successful()) {
            $this->logAutoMerge('GitLab auto-merge not enabled', $owner, $repo, $number, $response->status(), $this->errorMessage($response->json()));

            return false;
        }

        return $response->json('merge_when_pipeline_succeeds') === true || $response->json('state') === 'merged';
    }

    public function disableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        $mr = $this->getPullRequest($owner, $repo, $number);

        if (($mr['merge_when_pipeline_succeeds'] ?? false) !== true) {
            return true;
        }

        $response = $this->connector->send(new CancelMergeWhenPipelineSucceeds($owner, $repo, $number));

        if ($response->serverError()) {
            $response->throw();
        }

        // 406: "already canceled" — the desired end state.
        if ($response->successful() || $response->status() === 406) {
            return true;
        }

        $this->logAutoMerge('GitLab auto-merge not disabled', $owner, $repo, $number, $response->status(), $this->errorMessage($response->json()));

        return false;
    }

    public function failedCheckLog(string $owner, string $repo, int|string $number): string
    {
        $mr = $this->getPullRequest($owner, $repo, $number);
        $pipelineId = $mr['head_pipeline']['id'] ?? null;

        if (! is_int($pipelineId) && ! is_string($pipelineId)) {
            return '';
        }

        $sections = [];

        foreach (array_slice($this->failedJobs($owner, $repo, $pipelineId), 0, 5) as $job) {
            $lines = ['## '.($this->stringOrNull($job['name'] ?? null) ?? 'job').' (stage '.($job['stage'] ?? '?').', failed'.(isset($job['failure_reason']) ? ': '.$job['failure_reason'] : '').')'];
            if (($jobUrl = $this->stringOrNull($job['web_url'] ?? null)) !== null) {
                $lines[] = $jobUrl;
            }

            if (isset($job['id'])) {
                $trace = $this->trace($owner, $repo, $job['id']);
                if ($trace !== '') {
                    $lines[] = "Log (tail):\n".$trace;
                }
            }

            $sections[] = implode("\n", $lines);
        }

        return $sections === [] ? '' : $this->tail(implode("\n\n", $sections));
    }

    /**
     * @param  array<string, mixed>  $mr
     * @return 'draft'|'open'|'merged'|'closed'
     */
    private function mergeRequestState(array $mr): string
    {
        return match ($mr['state'] ?? null) {
            'merged' => 'merged',
            'opened', 'locked' => ($mr['draft'] ?? $mr['work_in_progress'] ?? false) === true ? 'draft' : 'open',
            default => 'closed',
        };
    }

    /**
     * @param  array<string, mixed>  $mr
     * @return array{state: 'pending'|'success'|'failure'|'none', url: ?string, failed: list<array{name: string, url: ?string}>}
     */
    private function checks(string $owner, string $repo, array $mr): array
    {
        $pipeline = $mr['head_pipeline'] ?? null;

        if (! is_array($pipeline) || ! isset($pipeline['id'])) {
            return ['state' => 'none', 'url' => null, 'failed' => []];
        }

        $status = $pipeline['status'] ?? null;
        $state = match (true) {
            $status === 'success' => 'success',
            $status === 'failed', $status === 'canceled' => 'failure',
            $status === 'skipped', ! is_string($status) => 'none',
            default => 'pending',
        };

        $failed = [];
        foreach ($this->failedJobs($owner, $repo, $pipeline['id']) as $job) {
            $failed[] = ['name' => (string) ($job['name'] ?? 'job'), 'url' => $this->stringOrNull($job['web_url'] ?? null)];
        }

        return [
            'state' => $state,
            'url' => $this->stringOrNull($pipeline['web_url'] ?? null),
            'failed' => $failed,
        ];
    }

    /**
     * Failed jobs of a pipeline that block it (allow_failure jobs excluded).
     *
     * @return list<array<string, mixed>>
     */
    private function failedJobs(string $owner, string $repo, int|string $pipelineId): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $this->optionalFetch('pipeline jobs', fn (): mixed => $this->connector->send(new ListFailedPipelineJobs($owner, $repo, $pipelineId))->throw()->json());

        return array_values(array_filter($jobs, fn (array $job): bool => ($job['allow_failure'] ?? false) !== true));
    }

    private function trace(string $owner, string $repo, int|string $jobId): string
    {
        try {
            $response = $this->connector->send(new GetJobTrace($owner, $repo, $jobId));

            return $response->successful() ? $this->tail($this->cleanLog($response->body()), self::CHECK_SECTION_LIMIT) : '';
        } catch (\Throwable $e) {
            Log::channel('argos')->info('GitLab job trace unavailable', ['owner_repo' => "{$owner}/{$repo}", 'job' => $jobId, 'class' => $e::class]);

            return '';
        }
    }

    /**
     * Non-system notes of all threads, oldest first.
     *
     * @param  list<array<string, mixed>>  $discussions
     * @return list<array{id: string, author: string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}>
     */
    private function comments(array $discussions, ?string $mrUrl): array
    {
        $comments = [];

        foreach ($discussions as $discussion) {
            foreach (is_array($discussion['notes'] ?? null) ? $discussion['notes'] : [] as $note) {
                if (! is_array($note) || ($note['system'] ?? false) === true) {
                    continue;
                }

                $position = is_array($note['position'] ?? null) ? $note['position'] : [];
                $id = (string) ($note['id'] ?? '');

                $comments[] = [
                    'id' => $id,
                    'author' => (string) ($note['author']['username'] ?? ''),
                    'body' => (string) ($note['body'] ?? ''),
                    'path' => $this->stringOrNull($position['new_path'] ?? $position['old_path'] ?? null),
                    'line' => $this->intOrNull($position['new_line'] ?? $position['old_line'] ?? null),
                    'url' => $mrUrl !== null && $id !== '' ? "{$mrUrl}#note_{$id}" : null,
                    'created_at' => $this->stringOrNull($note['created_at'] ?? null),
                ];
            }
        }

        return $this->sortComments($comments);
    }

    /**
     * @param  list<array<string, mixed>>  $discussions
     */
    private function hasUnresolvedThreads(array $discussions): bool
    {
        foreach ($discussions as $discussion) {
            foreach (is_array($discussion['notes'] ?? null) ? $discussion['notes'] : [] as $note) {
                if (is_array($note) && ($note['system'] ?? false) !== true && ($note['resolvable'] ?? false) === true && ($note['resolved'] ?? false) !== true) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $approvals
     * @return list<string>
     */
    private function approvedBy(array $approvals): array
    {
        $users = [];

        foreach (is_array($approvals['approved_by'] ?? null) ? $approvals['approved_by'] : [] as $entry) {
            $username = is_array($entry) ? ($entry['user']['username'] ?? null) : null;
            if (is_string($username) && $username !== '') {
                $users[] = $username;
            }
        }

        return $users;
    }

    private function errorMessage(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $message = $json['message'] ?? $json['error'] ?? null;

        return is_string($message) ? $message : (is_array($message) ? (string) json_encode($message) : null);
    }

    private function logAutoMerge(string $message, string $owner, string $repo, int|string $number, ?int $status = null, ?string $error = null): void
    {
        Log::channel('argos')->info($message, array_filter([
            'owner_repo' => "{$owner}/{$repo}",
            'number' => $number,
            'status' => $status,
            'error' => $error,
        ]));
    }
}
