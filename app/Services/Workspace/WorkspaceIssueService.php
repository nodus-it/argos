<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderSyncStatus;
use App\Models\RepoProfile;
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
 * Start a workspace from an issue of the project's bound tracker: search and
 * load the issue, derive name/branch, prefill the first message and link the
 * pull request back to the issue when shipping. The issue is never closed
 * here — merging the PR (`Closes #…`) does that.
 */
class WorkspaceIssueService
{
    use ParsesExternalProjectRef;

    public const BRANCH_MAX = 50;

    private const LIST_CACHE_SECONDS = 60;

    private const BODY_LIMIT = 20_000;

    public function __construct(
        private readonly IssueTrackerRegistry $registry,
        private readonly UntrustedInput $untrusted,
    ) {}

    /** The project's first usable tracker binding, if any. */
    public function bindingFor(?RepoProfile $profile): ?TaskProviderBinding
    {
        if ($profile === null) {
            return null;
        }

        return $profile->taskProviderBindings()
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
    public function search(RepoProfile $profile, string $query, int $limit = 30): array
    {
        $binding = $this->bindingFor($profile);
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
     * Load one issue as stored on the workspace.
     *
     * @return array{binding_id: string, provider: string, owner: string, project: string, number: string, title: string, url: string, body: string}
     */
    public function load(RepoProfile $profile, string $number): array
    {
        $binding = $this->bindingFor($profile) ?? throw WorkspaceException::translated('workspaces.issue.no_binding');
        [$owner, $project] = $this->parseRef((string) $binding->external_project_ref);

        try {
            $issue = ExternalIssue::fromProvider($this->registry->make($binding->kind, $binding)->getIssue($owner, $project, $number), $binding->kind);
        } catch (Throwable $e) {
            throw WorkspaceException::translated('workspaces.issue.load_failed', ['message' => $e->getMessage()]);
        }

        return [
            'binding_id' => $binding->id,
            'provider' => $binding->kind->value,
            'owner' => $owner,
            'project' => $project,
            'number' => $issue->externalId !== '' ? $issue->externalId : $number,
            'title' => $issue->title,
            'url' => $issue->url,
            'body' => mb_substr($issue->body, 0, self::BODY_LIMIT),
        ];
    }

    public function label(string $number, string $title): string
    {
        return '#'.$this->shortNumber($number).' '.$title;
    }

    /** `ws/<nr>-<slug-of-title>`, at most BRANCH_MAX characters. */
    public function branchFor(string $number, string $title): string
    {
        $branch = 'ws/'.trim($this->shortNumber($number).'-'.Str::slug($title), '-');

        return rtrim(mb_substr($branch, 0, self::BRANCH_MAX), '-');
    }

    /** The prefilled first message: the issue, wrapped as untrusted input. */
    public function composerDraft(Workspace $workspace): string
    {
        $issue = $workspace->external_issue;
        if ($issue === null) {
            return '';
        }

        $text = "# {$issue['title']}\n\n".($issue['body'] !== '' ? $issue['body'] : '(no description)')."\n\n".$issue['url'];

        return __('workspaces.issue.draft_intro', ['label' => $this->label($issue['number'], '')])."\n\n"
            .$this->untrusted->wrapText($text, true, 'ISSUE');
    }

    /** Line for the PR body that links (and on merge closes) the issue. */
    public function pullRequestReference(Workspace $workspace): ?string
    {
        $issue = $workspace->external_issue;
        if ($issue === null) {
            return null;
        }

        return match (TaskProviderKind::tryFrom($issue['provider'])) {
            TaskProviderKind::GitHub, TaskProviderKind::GitLab => 'Closes #'.$issue['number'],
            default => $issue['url'] !== '' ? __('workspaces.issue.pr_reference', ['url' => $issue['url']]) : null,
        };
    }

    /** Tell the issue that a pull request exists; failures are only logged. */
    public function announcePullRequest(Workspace $workspace, string $prUrl): void
    {
        $issue = $workspace->external_issue;
        if ($issue === null) {
            return;
        }

        $binding = TaskProviderBinding::query()->find($issue['binding_id']);
        if ($binding === null || ! $this->registry->has($binding->kind)) {
            return;
        }

        try {
            $this->registry->make($binding->kind, $binding)
                ->createComment($issue['owner'], $issue['project'], $issue['number'], __('workspaces.issue.pr_comment', ['url' => $prUrl, 'workspace' => $workspace->name]));
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Workspace issue comment failed', ['workspace' => $workspace->slug, 'error' => $e->getMessage()]);
        }
    }

    /** Linear ids are UUIDs — keep labels and branches short. */
    private function shortNumber(string $number): string
    {
        return ctype_digit($number) ? $number : mb_substr($number, 0, 8);
    }
}
