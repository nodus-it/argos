<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\Git\WorkspaceDiffService;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\GitProvider\PullRequestInfo;
use App\Services\IssueTracker\IssueRunReporter;
use App\Support\TaskRefs;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commit, push and open (or update) a task's pull/merge request — as two
 * steps: Claude commits finished parts on its own, a person commits what is
 * left (`commit()`) and pushes with the pull request (`push()`); `ship()`
 * does both at once for automatic runs and the API. Commit message and pull
 * request text are drafted by Claude on the acting person's subscription.
 * Commits carry that person; branch and pull request use the workspace's
 * repository access.
 */
class WorkspaceShipService
{
    /** Diff bytes handed to the drafting prompts. */
    private const PROMPT_DIFF_LIMIT = 60_000;

    public function __construct(
        private readonly WorkspaceDaemonClient $daemon,
        private readonly AgentPool $pool,
        private readonly GitServiceFactory $git,
        private readonly TaskService $tasks,
        private readonly WorkspaceNotifier $notifier,
        private readonly WorkspaceIssueService $issues,
    ) {}

    /**
     * Draft a commit message for what is not committed yet (or, when all is
     * committed, for the whole branch). Falls back to an empty draft (the
     * person writes it) when no agent is usable or Claude or the daemon fails.
     *
     * @return array{subject: string, body: string, changed_files: int, ahead: int}
     */
    public function draft(Task $task, User $by): array
    {
        Gate::forUser($by)->authorize('ship', $task);
        $api = $this->daemon->for($task->workspace, 120);
        $empty = ['subject' => '', 'body' => '', 'changed_files' => 0, 'ahead' => 0];

        try {
            $status = $api->gitStatus($task->slug);
            $empty = [...$empty, 'changed_files' => count($status['files']), 'ahead' => (int) $status['ahead']];
            // HEAD: diff against the last commit — exactly what this commit takes.
            $base = $status['files'] !== [] ? 'HEAD' : 'origin/'.$task->base_branch;
            $structured = $this->ask($task, $by, $api, 'commit-message', $this->prompt($task, $base, $api->gitDiff($task->slug, $base)['diff'], 'commit'));
        } catch (Throwable $e) {
            Log::channel('argos')->info('Commit message draft failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return $empty;
        }

        return [
            ...$empty,
            'subject' => mb_substr(trim((string) ($structured['subject'] ?? '')), 0, 120),
            'body' => trim((string) ($structured['body'] ?? '')),
        ];
    }

    /**
     * Draft title and description of the pull request from the whole branch
     * (diff against the base) and the conversation. Empty when no agent is
     * usable or Claude or the daemon fails.
     *
     * @return array{title: string, body: string}
     */
    public function pullRequestDraft(Task $task, User $by): array
    {
        Gate::forUser($by)->authorize('ship', $task);
        $api = $this->daemon->for($task->workspace, 120);
        $base = 'origin/'.$task->base_branch;

        try {
            $structured = $this->ask($task, $by, $api, 'pull-request', $this->prompt($task, $base, $api->gitDiff($task->slug, $base)['diff'], 'pull_request'));
        } catch (Throwable $e) {
            Log::channel('argos')->info('Pull request draft failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return ['title' => '', 'body' => ''];
        }

        return [
            'title' => mb_substr(trim((string) ($structured['title'] ?? '')), 0, 200),
            'body' => trim((string) ($structured['body'] ?? '')),
        ];
    }

    /**
     * What is not pushed yet: changed files (uncommitted) and commits ahead.
     *
     * @return array{changed_files: int, ahead: int}
     */
    public function pending(Task $task): array
    {
        // The same (briefly cached) numbers the action bar shows.
        $summary = app(WorkspaceDiffService::class)->summary($task);

        return ['changed_files' => $summary['uncommitted'], 'ahead' => $summary['ahead']];
    }

    /**
     * Commit everything not committed yet, as `$by`. Returns the commit SHA.
     */
    public function commit(Task $task, User $by, string $subject, string $body): string
    {
        Gate::forUser($by)->authorize('ship', $task);
        $subject = trim($subject);
        if ($subject === '') {
            throw WorkspaceException::translated('workspaces.ship.subject_required');
        }

        $commit = WorkspaceException::wrapDaemon(fn () => $this->daemon->for($task->workspace, 120)->gitCommit($task->slug, $subject, $body, ['name' => $by->name, 'email' => $by->email]));
        if ($commit === null) {
            throw WorkspaceException::translated('workspaces.ship.nothing_to_commit');
        }
        app(WorkspaceDiffService::class)->forget($task);
        $this->tasks->notice($task, 'committed', $by, ['subject' => $subject]);

        return $commit['sha'];
    }

    /**
     * Push the task branch and open the pull request — or, when it is open
     * already, tell it about the new commits. Returns the PR URL.
     *
     * @param  string|null  $title  the new pull request's title (default: the task title)
     * @param  bool  $pullRequest  false: push only (auto-tasks with ship mode `none`)
     */
    public function push(Task $task, User $by, ?string $title = null, string $body = '', bool $draft = false, bool $pullRequest = true): string
    {
        Gate::forUser($by)->authorize('ship', $task);
        $api = $this->daemon->for($task->workspace, 300);
        if (WorkspaceException::wrapDaemon(fn () => (int) $api->gitStatus($task->slug)['ahead'] === 0 && $this->nothingToShip($task, $api))) {
            throw WorkspaceException::translated('workspaces.ship.nothing');
        }

        return $this->pushAndOpen($task, $by, $api, trim((string) $title) !== '' ? trim((string) $title) : $task->title, $body, $draft, $pullRequest);
    }

    /**
     * Commit, push and open/update the pull request in one go (automatic
     * runs, API, MCP). Returns the PR URL.
     *
     * @param  bool  $pullRequest  false: commit and push only (auto-tasks with ship mode `none`)
     */
    public function ship(Task $task, User $by, string $subject, string $body, bool $draft = false, bool $pullRequest = true): string
    {
        Gate::forUser($by)->authorize('ship', $task);
        $subject = trim($subject);
        if ($subject === '') {
            throw WorkspaceException::translated('workspaces.ship.subject_required');
        }

        $api = $this->daemon->for($task->workspace, 300);
        $nothing = WorkspaceException::wrapDaemon(fn () => $api->gitCommit($task->slug, $subject, $body, ['name' => $by->name, 'email' => $by->email]) === null
            && (int) $api->gitStatus($task->slug)['ahead'] === 0 && $this->nothingToShip($task, $api));
        if ($nothing) {
            throw WorkspaceException::translated('workspaces.ship.nothing');
        }

        return $this->pushAndOpen($task, $by, $api, $subject, $body, $draft, $pullRequest);
    }

    private function pushAndOpen(Task $task, User $by, WorkspaceDaemonTransport $api, string $title, string $body, bool $draft, bool $pullRequest): string
    {
        $workspace = $task->workspace;
        WorkspaceException::wrapDaemon(fn () => $api->gitPush($task->slug, (string) $task->branch), [
            'rejected_non_fast_forward' => 'workspaces.ship.rejected',
            'auth' => 'workspaces.ship.auth',
        ]);
        app(WorkspaceDiffService::class)->forget($task);

        if (! $pullRequest) {
            $task->forceFill(['shipped_at' => now()])->save();

            return (string) $task->pr_url;
        }

        [$owner, $repo] = $workspace->ownerAndRepo();

        // The branch is pushed by now; a provider failure leaves it there and
        // pushing again retries only the pull request part.
        try {
            $service = $this->git->forWorkspace($workspace);
            if ($task->pr_number === null) {
                $raw = $service->createPullRequest($owner, $repo, $title, $this->pullRequestBody($task, $body, $by), (string) $task->branch, $task->base_branch, ['draft' => $draft]);
                $info = PullRequestInfo::from($workspace->platform, $raw);
                $task->forceFill([
                    'pr_url' => $info->url,
                    'pr_number' => $info->number,
                    'pr_state' => $draft ? 'draft' : ($info->state ?? 'open'),
                    'pr_synced_at' => now(),
                    'status' => TaskStatus::PrOpen,
                ]);
                // Never throws: tracker failures are only logged.
                app(IssueRunReporter::class)->pullRequest($task, (string) $info->url);
            } else {
                $service->commentOnPullRequest($owner, $repo, $task->pr_number, __('workspaces.ship.pr_comment', ['subject' => $title, 'name' => $by->name])."\n\n".PullRequestService::ARGOS_MARKER);
            }
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Pull request step of ship failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            throw WorkspaceException::translated('workspaces.ship.pr_failed', ['message' => $e->getMessage()]);
        }

        $task->forceFill(['shipped_at' => now()])->save();
        app(PullRequestService::class)->applyAutoMerge($task);
        $this->tasks->notice($task, 'shipped', $by, ['url' => (string) $task->pr_url]);
        $this->notifier->notify($workspace, $task, 'ship', __('workspaces.notifications.ship', ['name' => $by->name]), (string) $task->pr_url, $by->id);

        return (string) $task->pr_url;
    }

    /** Refresh the PR state from the provider (see PullRequestService::refresh). */
    public function syncPullRequest(Task $task): void
    {
        app(PullRequestService::class)->refresh($task);
    }

    /**
     * Nothing new to ship: with a PR there is no new commit to push; without
     * one, the branch carries no change against the base.
     */
    private function nothingToShip(Task $task, WorkspaceDaemonTransport $api): bool
    {
        if ($task->pr_number !== null) {
            return true;
        }

        return trim($api->gitDiff($task->slug, 'origin/'.$task->base_branch)['diff']) === '';
    }

    /**
     * One structured Claude call (Haiku) with a prompt/schema pair from
     * `worker/`. Empty when no agent of `$by` is usable.
     *
     * @return array<string, mixed>
     */
    private function ask(Task $task, User $by, WorkspaceDaemonTransport $api, string $name, string $userPrompt): array
    {
        $agent = $this->pool->usableAgentsFor($by)->first();
        if ($agent === null) {
            return [];
        }

        $result = $api->oneshot([
            'agent_id' => $agent->id,
            'oauth_token' => (string) ($agent->credentials['token'] ?? ''),
            // Alias: always the newest Haiku the subscription offers.
            'model' => 'haiku',
            'system_prompt' => (string) file_get_contents(base_path("worker/prompts/{$name}.system.md")),
            'user_prompt' => $userPrompt,
            'json_schema' => json_decode((string) file_get_contents(base_path("worker/schemas/{$name}.schema.json")), true),
        ]);

        return is_array($result['structured']) ? $result['structured'] : [];
    }

    /** @param  'commit'|'pull_request'  $for */
    private function prompt(Task $task, string $base, string $diff, string $for): string
    {
        $lines = TaskEvent::query()
            ->where('task_id', $task->id)
            ->whereIn('type', [TaskEventType::UserMessage->value, TaskEventType::AssistantMessage->value])
            ->latest('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (TaskEvent $e): string => ($e->type === TaskEventType::UserMessage ? '['.($e->payload['author_name'] ?? 'User').']' : '[Claude]').' '.mb_substr((string) ($e->payload['text'] ?? ''), 0, 600))
            ->implode("\n");
        $ticket = $task->external_ref !== null ? trim(TaskRefs::ticketLabel($task).' '.($task->external_ref['title'] ?? '')) : null;

        return implode("\n", array_filter([
            $for === 'commit' ? '# Write the commit message' : '# Write the pull request',
            '',
            "Task: {$task->title}. Branch: {$task->branch} → {$task->base_branch}.",
            $ticket !== null ? "Ticket: {$ticket}" : null,
            '',
            '## Conversation excerpt',
            $lines !== '' ? $lines : '(no conversation)',
            '',
            "## git diff {$base}",
            '```diff',
            mb_strcut($diff, 0, self::PROMPT_DIFF_LIMIT),
            '```',
        ], fn (?string $line): bool => $line !== null));
    }

    private function pullRequestBody(Task $task, string $body, User $by): string
    {
        $reference = $this->issues->pullRequestReference($task);

        return trim($body).($reference !== null ? "\n\n".$reference : '')
            ."\n\n---\n".__('workspaces.ship.pr_footer', ['url' => $task->url(), 'name' => $by->name]);
    }
}
