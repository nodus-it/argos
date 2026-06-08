<?php

declare(strict_types=1);

namespace Tests\Feature\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Integrations\GitHub\Requests\CloseIssue;
use App\Integrations\GitHub\Requests\CreateIssueComment;
use App\Models\ExternalIssueLink;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueStatusSync;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $bindingAttributes
 */
function statusSyncTask(array $bindingAttributes = [], bool $withLink = true): Task
{
    $profile = RepoProfile::factory()->create();
    $task = Task::factory()->for($profile, 'repoProfile')->create([
        'pr_url' => 'https://github.com/acme/widget/pull/9',
    ]);

    if ($withLink) {
        $binding = TaskProviderBinding::factory()->create(array_merge([
            'repo_profile_id' => $profile->id,
            'kind' => TaskProviderKind::GitHub,
            'external_project_ref' => 'acme/widget',
            'filters' => ['close_on_complete' => true],
        ], $bindingAttributes));

        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => '42',
        ]);
    }

    return $task;
}

test('closes the source issue and comments the PR link when opted in', function () {
    Saloon::fake(['https://api.github.com/*' => MockResponse::make([], 200)]);

    $task = statusSyncTask();

    app(IssueStatusSync::class)->closeSourceIssue($task);

    // Closing PATCH on the issue itself.
    Saloon::assertSent(fn (Request $r): bool => $r instanceof CloseIssue
        && $r->resolveEndpoint() === '/repos/acme/widget/issues/42'
        && ($r->body()->all()['state'] ?? null) === 'closed');

    // Closing comment with the PR link.
    Saloon::assertSent(fn (Request $r): bool => $r instanceof CreateIssueComment
        && str_contains($r->resolveEndpoint(), '/issues/42/comments')
        && str_contains((string) ($r->body()->all()['body'] ?? ''), 'pull/9'));
});

test('does nothing when the binding has not opted in', function () {
    Saloon::fake(['https://api.github.com/*' => MockResponse::make([], 200)]);

    $task = statusSyncTask(['filters' => ['close_on_complete' => false]]);

    app(IssueStatusSync::class)->closeSourceIssue($task);

    Saloon::assertNothingSent();
});

test('does nothing when the task has no external issue link', function () {
    Saloon::fake(['https://api.github.com/*' => MockResponse::make([], 200)]);

    $task = statusSyncTask(withLink: false);

    app(IssueStatusSync::class)->closeSourceIssue($task);

    Saloon::assertNothingSent();
});

test('is best-effort — a provider error does not bubble up', function () {
    Saloon::fake(['https://api.github.com/*' => MockResponse::make(['message' => 'boom'], 500)]);

    $task = statusSyncTask();

    app(IssueStatusSync::class)->closeSourceIssue($task);
})->throwsNoExceptions();

test('TaskService::markCompleted wires through to the source-issue close', function () {
    Saloon::fake(['https://api.github.com/*' => MockResponse::make([], 200)]);
    Process::fake(); // swallow the `docker volume rm` side-effect

    $task = statusSyncTask();

    app(TaskService::class)->markCompleted($task);

    Saloon::assertSent(fn (Request $r): bool => $r instanceof CloseIssue
        && str_contains($r->resolveEndpoint(), '/repos/acme/widget/issues/42'));
});
