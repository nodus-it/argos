<?php

declare(strict_types=1);

namespace Tests\Feature\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ExternalIssueLink;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueStatusSync;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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
    Http::fake(['https://api.github.com/*' => Http::response([], 200)]);

    $task = statusSyncTask();

    app(IssueStatusSync::class)->closeSourceIssue($task);

    // Closing PATCH on the issue itself.
    Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
        && str_starts_with($r->url(), 'https://api.github.com/repos/acme/widget/issues/42')
        && $r['state'] === 'closed');

    // Closing comment with the PR link.
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/issues/42/comments')
        && str_contains((string) $r['body'], 'pull/9'));
});

test('does nothing when the binding has not opted in', function () {
    Http::fake(['https://api.github.com/*' => Http::response([], 200)]);

    $task = statusSyncTask(['filters' => ['close_on_complete' => false]]);

    app(IssueStatusSync::class)->closeSourceIssue($task);

    Http::assertNothingSent();
});

test('does nothing when the task has no external issue link', function () {
    Http::fake(['https://api.github.com/*' => Http::response([], 200)]);

    $task = statusSyncTask(withLink: false);

    app(IssueStatusSync::class)->closeSourceIssue($task);

    Http::assertNothingSent();
});

test('is best-effort — a provider error does not bubble up', function () {
    Http::fake(['https://api.github.com/*' => Http::response(['message' => 'boom'], 500)]);

    $task = statusSyncTask();

    app(IssueStatusSync::class)->closeSourceIssue($task);
})->throwsNoExceptions();

test('TaskService::markCompleted wires through to the source-issue close', function () {
    Http::fake(['https://api.github.com/*' => Http::response([], 200)]);
    Process::fake(); // swallow the `docker volume rm` side-effect

    $task = statusSyncTask();

    app(TaskService::class)->markCompleted($task);

    Http::assertSent(fn ($r): bool => $r->method() === 'PATCH'
        && str_contains($r->url(), '/repos/acme/widget/issues/42'));
});
