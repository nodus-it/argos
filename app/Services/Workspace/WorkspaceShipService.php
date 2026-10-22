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
use App\Services\GitProvider\GitServiceFactory;
use App\Services\GitProvider\PullRequestInfo;
use App\Services\IssueTracker\IssueRunReporter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Ship" a task: commit what changed, push its branch and open (or update)
 * its pull/merge request. The commit message is drafted by Claude from the
 * diff and the conversation, on the shipping user's subscription. Commits
 * carry the shipping person; branch and pull request use the workspace's
 * repository access.
 */
class WorkspaceShipService
{
    /** Diff bytes handed to the commit-message prompt. */
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
     * Draft a commit message. Falls back to an empty draft (the person
     * writes it) when no agent is usable or Claude or the daemon fails.
     *
     * @return array{subject: string, body: string, changed_files: int, ahead: int}
     */
    public function draft(Task $task, User $by): array
    {
        Gate::forUser($by)->authorize('ship', $task);
        $workspace = $task->workspace;
        $api = $this->daemon->for($workspace, 120);
        $empty = ['subject' => '', 'body' => '', 'changed_files' => 0, 'ahead' => 0];

        try {
            $status = $api->gitStatus($task->slug);
            $empty = [...$empty, 'changed_files' => count($status['files']), 'ahead' => (int) $status['ahead']];

            $agent = $this->pool->usableAgentsFor($by)->first();
            if ($agent === null) {
                return $empty;
            }

            $diff = $api->gitDiff($task->slug, 'origin/'.$task->base_branch)['diff'];
            $result = $api->oneshot([
                'agent_id' => $agent->id,
                'oauth_token' => (string) ($agent->credentials['token'] ?? ''),
                // Alias: always the newest Haiku the subscription offers.
                'model' => 'haiku',
                'system_prompt' => (string) file_get_contents(base_path('worker/prompts/commit-message.system.md')),
                'user_prompt' => $this->prompt($task, $diff),
                'json_schema' => json_decode((string) file_get_contents(base_path('worker/schemas/commit-message.schema.json')), true),
            ]);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Commit message draft failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return $empty;
        }

        $structured = is_array($result['structured']) ? $result['structured'] : [];

        return [
            ...$empty,
            'subject' => mb_substr(trim((string) ($structured['subject'] ?? '')), 0, 120),
            'body' => trim((string) ($structured['body'] ?? '')),
        ];
    }

    /**
     * Commit, push and open/update the pull request. Returns the PR URL.
     *
     * @param  bool  $pullRequest  false: commit and push only (auto-tasks with ship mode `none`)
     */
    public function ship(Task $task, User $by, string $subject, string $body, bool $draft = false, bool $pullRequest = true): string
    {
        Gate::forUser($by)->authorize('ship', $task);
        $workspace = $task->workspace;
        $subject = trim($subject);
        if ($subject === '') {
            throw WorkspaceException::translated('workspaces.ship.subject_required');
        }

        $api = $this->daemon->for($workspace, 300);
        try {
            $commit = $api->gitCommit($task->slug, $subject, $body, ['name' => $by->name, 'email' => $by->email]);
            if ($commit === null && (int) $api->gitStatus($task->slug)['ahead'] === 0 && $this->nothingToShip($task, $api)) {
                throw WorkspaceException::translated('workspaces.ship.nothing');
            }
            $api->gitPush($task->slug, (string) $task->branch);
        } catch (DaemonTransportException $e) {
            throw WorkspaceException::fromDaemon($e, [
                'rejected_non_fast_forward' => 'workspaces.ship.rejected',
                'auth' => 'workspaces.ship.auth',
            ]);
        }

        if (! $pullRequest) {
            $task->forceFill(['shipped_at' => now()])->save();

            return (string) $task->pr_url;
        }

        [$owner, $repo] = $workspace->ownerAndRepo();

        // The branch is pushed by now; a provider failure leaves it there and
        // shipping again retries only the pull request part.
        try {
            $service = $this->git->forWorkspace($workspace);
            if ($task->pr_number === null) {
                $raw = $service->createPullRequest($owner, $repo, $subject, $this->pullRequestBody($task, $body, $by), (string) $task->branch, $task->base_branch, ['draft' => $draft]);
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
                $service->commentOnPullRequest($owner, $repo, $task->pr_number, __('workspaces.ship.pr_comment', ['subject' => $subject, 'name' => $by->name])."\n\n".PullRequestService::ARGOS_MARKER);
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

    private function prompt(Task $task, string $diff): string
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

        return implode("\n", [
            '# Commit-Message generieren',
            '',
            'Kein Konzept-Dokument: Dies ist eine interaktive Aufgabe ('.$task->title.'). Der Gesprächsauszug unten beschreibt, worum es ging.',
            '',
            '## Gesprächsauszug',
            $lines !== '' ? $lines : '(kein Verlauf)',
            '',
            "## git diff origin/{$task->base_branch}",
            '```diff',
            mb_strcut($diff, 0, self::PROMPT_DIFF_LIMIT),
            '```',
        ]);
    }

    private function pullRequestBody(Task $task, string $body, User $by): string
    {
        $reference = $this->issues->pullRequestReference($task);

        return trim($body).($reference !== null ? "\n\n".$reference : '')
            ."\n\n---\n".__('workspaces.ship.pr_footer', ['url' => $task->url(), 'name' => $by->name]);
    }
}
