<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderSyncStatus;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\Workspace;
use App\Services\IssueTracker\Concerns\ParsesExternalProjectRef;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Support\UntrustedInput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tickets of a task: search and load issues of the workspace's bound
 * tracker, prefill the first message, comment on the issue and link the pull
 * request back to it. GitHub and GitLab close the issue through `Closes #…`
 * in the pull request when the integration says so.
 */
class WorkspaceIssueService
{
    use ParsesExternalProjectRef;

    private const LIST_CACHE_SECONDS = 60;

    private const BODY_LIMIT = 20_000;

    public function __construct(
        private readonly IssueTrackerRegistry $registry,
        private readonly UntrustedInput $untrusted,
    ) {}

    /** The workspace's first usable tracker binding, if any. */
    public function bindingFor(?Workspace $workspace): ?TaskProviderBinding
    {
        if ($workspace === null) {
            return null;
        }

        return $workspace->taskProviderBindings()
            ->where('sync_status', TaskProviderSyncStatus::Active->value)
            ->whereNotNull('external_project_ref')
            ->get()
            ->first(fn (TaskProviderBinding $b): bool => $this->registry->has($b->kind));
    }

    /**
     * Open issues matching a title or number fragment, as select options.
     *
     * @return array<string, string> number => "#number title"
     */
    public function search(Workspace $workspace, string $query, int $limit = 30): array
    {
        $binding = $this->bindingFor($workspace);
        if ($binding === null) {
            return [];
        }

        try {
            $issues = Cache::remember('ws-issues:'.$binding->id, self::LIST_CACHE_SECONDS, function () use ($binding): array {
                [$owner, $project] = $this->parseRef((string) $binding->external_project_ref);

                return array_map(
                    fn (array $raw): array => ['number' => ExternalIssue::fromProvider($raw, $binding->kind)->externalId, 'title' => (string) ($raw['title'] ?? '')],
                    $this->registry->make($binding->kind, $binding)->listIssues($owner, $project, ['state' => 'open']),
                );
            });
        } catch (Throwable $e) {
            Log::channel('argos')->info('Workspace issue search failed', ['binding' => $binding->id, 'error' => $e->getMessage()]);

            return [];
        }

        $query = Str::lower(ltrim(trim($query), '#'));
        $options = [];
        foreach ($issues as $issue) {
            if ($query !== '' && ! str_contains(Str::lower($issue['title']), $query) && ! str_starts_with(Str::lower($issue['number']), $query)) {
                continue;
            }
            $options[$issue['number']] = $this->label($issue['number'], $issue['title']);
            if (count($options) >= $limit) {
                break;
            }
        }

        return $options;
    }

    /**
     * Load one issue as stored on the task (`external_ref`).
     *
     * @return array{binding_id: string, provider: string, key: string, owner: string, project: string, number: string, title: string, url: string, body: string}
     */
    public function load(Workspace $workspace, string $number): array
    {
        $binding = $this->bindingFor($workspace) ?? throw WorkspaceException::translated('workspaces.issue.no_binding');
        [$owner, $project] = $this->parseRef((string) $binding->external_project_ref);

        try {
            $issue = ExternalIssue::fromProvider($this->registry->make($binding->kind, $binding)->getIssue($owner, $project, $number), $binding->kind);
        } catch (Throwable $e) {
            throw WorkspaceException::translated('workspaces.issue.load_failed', ['message' => $e->getMessage()]);
        }

        $number = $issue->externalId !== '' ? $issue->externalId : $number;

        return [
            'binding_id' => $binding->id,
            'provider' => $binding->kind->value,
            'key' => $this->key($binding->kind, $number, $issue->key),
            'owner' => $owner,
            'project' => $project,
            'number' => $number,
            'title' => $issue->title,
            'url' => $issue->url,
            'body' => mb_substr($issue->body, 0, self::BODY_LIMIT),
        ];
    }

    /**
     * Open a ticket in the workspace's tracker; returns it as stored on the
     * task (`external_ref`).
     *
     * @return array{binding_id: string, provider: string, key: string, owner: string, project: string, number: string, title: string, url: string, body: string}
     */
    public function create(Workspace $workspace, string $title, string $body): array
    {
        $binding = $this->bindingFor($workspace) ?? throw WorkspaceException::translated('workspaces.issue.no_binding');
        [$owner, $project] = $this->parseRef((string) $binding->external_project_ref);

        try {
            $issue = ExternalIssue::fromProvider($this->registry->make($binding->kind, $binding)->createIssue($owner, $project, $title, $body), $binding->kind);
        } catch (Throwable $e) {
            throw WorkspaceException::translated('workspaces.issue.create_failed', ['message' => $e->getMessage()]);
        }

        return $this->fromExternal($issue, $binding);
    }

    /**
     * A ticket of the workspace's own system (custom integration), as stored
     * on the task.
     *
     * @param  array<string, mixed>  $ticket  key, title, url, body
     * @return array{binding_id: string, provider: string, key: string, owner: string, project: string, number: string, title: string, url: string, body: string}
     */
    public function customTicket(Workspace $workspace, array $ticket): array
    {
        $binding = $workspace->taskProviderBindings()->where('kind', TaskProviderKind::Custom->value)->first()
            ?? throw WorkspaceException::translated('workspaces.issue.no_custom_binding');
        $key = mb_substr(trim((string) ($ticket['key'] ?? '')), 0, 64);

        return [
            'binding_id' => $binding->id,
            'provider' => TaskProviderKind::Custom->value,
            'key' => $key,
            'owner' => '',
            'project' => (string) $binding->external_project_ref,
            'number' => $key,
            'title' => mb_substr(trim((string) ($ticket['title'] ?? '')), 0, 300),
            'url' => (string) ($ticket['url'] ?? ''),
            'body' => mb_substr((string) ($ticket['body'] ?? ''), 0, self::BODY_LIMIT),
        ];
    }

    public function label(string $number, string $title): string
    {
        return '#'.$this->shortNumber($number).' '.$title;
    }

    /**
     * Short ticket key shown in lists and used in branch names: the tracker's
     * own key where it has one ("ENG-118"), else "<provider>-<number>".
     */
    public function key(TaskProviderKind $kind, string $number, string $trackerKey = ''): string
    {
        return $trackerKey !== '' ? $trackerKey : $kind->value.'-'.$this->shortNumber($number);
    }

    /** The prefilled first message: the issue, wrapped as untrusted input. */
    public function composerDraft(Task $task): string
    {
        $issue = $task->external_ref;

        return $issue === null ? '' : $this->promptFor($issue);
    }

    /**
     * The task text for an issue: the issue wrapped as untrusted input.
     *
     * @param  array{number: string, title: string, url: string, body: string}  $issue
     */
    public function promptFor(array $issue): string
    {
        $text = "# {$issue['title']}\n\n".($issue['body'] !== '' ? $issue['body'] : '(no description)')."\n\n".$issue['url'];

        return __('workspaces.issue.draft_intro', ['label' => $this->label($issue['number'], '')])."\n\n"
            .$this->untrusted->wrapText($text, true, 'ISSUE');
    }

    /**
     * The task's issue data from an ingested issue (as load() returns it).
     *
     * @return array{binding_id: string, provider: string, key: string, owner: string, project: string, number: string, title: string, url: string, body: string}
     */
    public function fromExternal(ExternalIssue $issue, TaskProviderBinding $binding): array
    {
        [$owner, $project] = $this->parseRef((string) $binding->external_project_ref);

        return [
            'binding_id' => $binding->id,
            'provider' => $binding->kind->value,
            'key' => $this->key($binding->kind, $issue->externalId, $issue->key),
            'owner' => $owner,
            'project' => $project,
            'number' => $issue->externalId,
            'title' => $issue->title !== '' ? $issue->title : 'Issue '.$issue->externalId,
            'url' => $issue->url,
            'body' => mb_substr($issue->body, 0, self::BODY_LIMIT),
        ];
    }

    /** Post a comment on the task's issue; returns its id. Failures are only logged. */
    public function comment(Task $task, string $body): ?string
    {
        $issue = $task->external_ref;
        $binding = $issue !== null ? TaskProviderBinding::query()->find($issue['binding_id'] ?? null) : null;
        if ($issue === null || $binding === null || ! $this->registry->has($binding->kind)) {
            return null;
        }

        try {
            $tracker = $this->registry->make($binding->kind, $binding);

            return $tracker->commentId($tracker->createComment((string) ($issue['owner'] ?? ''), (string) ($issue['project'] ?? ''), $issue['number'], $body));
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Task issue comment failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Line for the PR body that links the issue — and on merge closes it
     * (GitHub/GitLab) unless the integration turned closing off.
     */
    public function pullRequestReference(Task $task): ?string
    {
        $issue = $task->external_ref;
        if ($issue === null) {
            return null;
        }

        $closes = TaskProviderBinding::query()->whereKey($issue['binding_id'] ?? null)->value('close_on_merge') ?? true;

        return match (TaskProviderKind::tryFrom($issue['provider'])) {
            TaskProviderKind::GitHub, TaskProviderKind::GitLab => ($closes ? 'Closes #' : 'Refs #').$issue['number'],
            default => $issue['url'] !== '' ? __('workspaces.issue.pr_reference', ['url' => $issue['url']]) : null,
        };
    }

    /** Tell the issue that a pull request exists; failures are only logged. */
    public function announcePullRequest(Task $task, string $prUrl): void
    {
        $this->comment($task, __('workspaces.issue.pr_comment', ['url' => $prUrl, 'workspace' => $task->workspace->name]));
    }

    /** Linear ids are UUIDs — keep labels and branches short. */
    private function shortNumber(string $number): string
    {
        return ctype_digit($number) ? $number : mb_substr($number, 0, 8);
    }
}
