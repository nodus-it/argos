<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ClaudeModel;
use App\Enums\ThreadEventType;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\GitProvider\PullRequestInfo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Ship" a workspace: commit what changed, push the branch and open (or
 * update) its pull/merge request. The commit message is drafted by Claude
 * from the diff and the threads, on the shipping user's subscription.
 */
class WorkspaceShipService
{
    /** Diff bytes handed to the commit-message prompt. */
    private const PROMPT_DIFF_LIMIT = 60_000;

    public function __construct(
        private readonly WorkspaceDaemonClient $daemon,
        private readonly AgentPool $pool,
        private readonly GitServiceFactory $git,
        private readonly ThreadStreamPublisher $publisher,
        private readonly WorkspaceNotifier $notifier,
    ) {}

    /**
     * Draft a commit message. Falls back to an empty draft (the person
     * writes it) when no agent is usable or Claude fails.
     *
     * @return array{subject: string, body: string, changed_files: int, ahead: int}
     */
    public function draft(Workspace $workspace, User $by): array
    {
        Gate::forUser($by)->authorize('ship', $workspace);
        $api = $this->daemon->for($workspace, 120);
        $status = $api->gitStatus();
        $empty = ['subject' => '', 'body' => '', 'changed_files' => count($status['files']), 'ahead' => (int) $status['ahead']];

        $agent = $this->pool->usableAgentsFor($by)->first();
        if ($agent === null) {
            return $empty;
        }

        try {
            $diff = $api->gitDiff('origin/'.$workspace->base_branch)['diff'];
            $result = $api->oneshot([
                'agent_id' => $agent->id,
                'oauth_token' => (string) ($agent->credentials['token'] ?? ''),
                'model' => ClaudeModel::default('commit-message')->value,
                'system_prompt' => (string) file_get_contents(base_path('worker/prompts/commit-message.system.md')),
                'user_prompt' => $this->prompt($workspace, $diff),
                'json_schema' => json_decode((string) file_get_contents(base_path('worker/schemas/commit-message.schema.json')), true),
            ]);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Commit message draft failed', ['workspace' => $workspace->slug, 'error' => $e->getMessage()]);

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
     */
    public function ship(Workspace $workspace, User $by, string $subject, string $body, bool $draft = false): string
    {
        Gate::forUser($by)->authorize('ship', $workspace);
        $subject = trim($subject);
        if ($subject === '') {
            throw WorkspaceException::translated('workspaces.ship.subject_required');
        }

        $api = $this->daemon->for($workspace, 300);
        try {
            $commit = $api->gitCommit($subject, $body, ['name' => $by->name, 'email' => $by->email]);
            if ($commit === null && (int) $api->gitStatus()['ahead'] === 0 && $this->nothingToShip($workspace, $api)) {
                throw WorkspaceException::translated('workspaces.ship.nothing');
            }
            $api->gitPush($workspace->branch);
        } catch (DaemonTransportException $e) {
            throw match ($e->errorCode) {
                'rejected_non_fast_forward' => WorkspaceException::translated('workspaces.ship.rejected'),
                'auth' => WorkspaceException::translated('workspaces.ship.auth', ['message' => $e->getMessage()]),
                default => WorkspaceException::translated('workspaces.errors.daemon_unreachable', ['message' => $e->getMessage()]),
            };
        }

        $profile = $workspace->repoProfile;
        [$owner, $repo] = $this->ownerRepo($profile->getOwnerRepo());
        $service = $this->git->fromRepoProfile($profile);

        if ($workspace->pr_number === null) {
            $raw = $service->createPullRequest($owner, $repo, $subject, $this->pullRequestBody($workspace, $body), $workspace->branch, $workspace->base_branch, ['draft' => $draft]);
            $info = PullRequestInfo::from($profile->platform, $raw);
            $workspace->forceFill([
                'pr_url' => $info->url,
                'pr_number' => $info->number,
                'pr_state' => $info->state ?? 'open',
                'pr_synced_at' => now(),
            ]);
        } else {
            $service->commentOnPullRequest($owner, $repo, $workspace->pr_number, __('workspaces.ship.pr_comment', ['subject' => $subject, 'name' => $by->name]));
        }

        $workspace->forceFill(['shipped_at' => now()])->save();
        $this->announce($workspace, $by, (string) $workspace->pr_url);
        $this->notifier->notify($workspace, null, 'ship', __('workspaces.notifications.ship', ['name' => $by->name]), (string) $workspace->pr_url, $by->id);

        return (string) $workspace->pr_url;
    }

    /** Refresh the PR state from the provider; failures keep the old state. */
    public function syncPullRequest(Workspace $workspace): void
    {
        if ($workspace->pr_number === null) {
            return;
        }

        $profile = $workspace->repoProfile;
        [$owner, $repo] = $this->ownerRepo($profile->getOwnerRepo());
        try {
            $info = PullRequestInfo::from($profile->platform, $this->git->fromRepoProfile($profile)->getPullRequest($owner, $repo, $workspace->pr_number));
        } catch (Throwable $e) {
            Log::channel('argos')->info('Pull request sync failed', ['workspace' => $workspace->slug, 'error' => $e->getMessage()]);

            return;
        }

        $workspace->forceFill([
            'pr_state' => $info->state ?? $workspace->pr_state,
            'pr_url' => $info->url ?? $workspace->pr_url,
            'pr_synced_at' => now(),
        ])->save();
    }

    /**
     * Nothing new to ship: with a PR there is no new commit to push; without
     * one, the branch carries no change against the base.
     */
    private function nothingToShip(Workspace $workspace, WorkspaceDaemonTransport $api): bool
    {
        if ($workspace->pr_number !== null) {
            return true;
        }

        return trim($api->gitDiff('origin/'.$workspace->base_branch)['diff']) === '';
    }

    /**
     * Split "owner/repo" (GitLab owners may contain subgroups).
     *
     * @return array{0: string, 1: string}
     */
    private function ownerRepo(string $ownerRepo): array
    {
        $parts = explode('/', $ownerRepo);
        $repo = (string) array_pop($parts);

        return [implode('/', $parts), $repo];
    }

    private function prompt(Workspace $workspace, string $diff): string
    {
        $lines = ThreadEvent::query()
            ->whereIn('thread_id', $workspace->threads()->pluck('id'))
            ->whereIn('type', [ThreadEventType::UserMessage->value, ThreadEventType::AssistantMessage->value])
            ->latest('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (ThreadEvent $e): string => ($e->type === ThreadEventType::UserMessage ? '['.($e->payload['author_name'] ?? 'User').']' : '[Claude]').' '.mb_substr((string) ($e->payload['text'] ?? ''), 0, 600))
            ->implode("\n");

        return implode("\n", [
            '# Commit-Message generieren',
            '',
            'Kein Konzept-Dokument: Dies ist ein interaktiver Workspace. Der Gesprächsauszug unten beschreibt, worum es ging.',
            '',
            '## Gesprächsauszug',
            $lines !== '' ? $lines : '(kein Verlauf)',
            '',
            "## git diff origin/{$workspace->base_branch}",
            '```diff',
            mb_strcut($diff, 0, self::PROMPT_DIFF_LIMIT),
            '```',
        ]);
    }

    private function pullRequestBody(Workspace $workspace, string $body): string
    {
        $link = url('/admin/workspaces/'.$workspace->id);

        return trim($body)."\n\n---\n".__('workspaces.ship.pr_footer', ['url' => $link]);
    }

    /** Note the shipment in every open thread and broadcast it. */
    private function announce(Workspace $workspace, User $by, string $url): void
    {
        $threads = Thread::query()->where('workspace_id', $workspace->id)->whereNull('archived_at')->get();
        foreach ($threads as $thread) {
            $event = ThreadEvent::query()->create([
                'thread_id' => $thread->id,
                'type' => ThreadEventType::Notice,
                'author_user_id' => $by->id,
                'payload' => ['kind' => 'shipped', 'author_name' => $by->name, 'url' => $url],
            ]);
            $this->publisher->publish($workspace->id, $thread->id, [['type' => 'notice', 'event_id' => $event->id, 'data' => $event->payload]]);
        }
    }
}
