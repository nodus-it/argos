<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Services\IssueTracker\DTO\ExternalIssue;
use Tests\TestCase;

class ExternalIssueTest extends TestCase
{
    public function test_github_assignee_comes_from_assignee_login(): void
    {
        $issue = ExternalIssue::fromProvider(['number' => 1, 'assignee' => ['login' => 'octocat']], TaskProviderKind::GitHub);

        $this->assertSame('octocat', $issue->assignee);
    }

    public function test_github_assignee_falls_back_to_first_of_assignees(): void
    {
        $issue = ExternalIssue::fromProvider(['number' => 1, 'assignee' => null, 'assignees' => [['login' => 'first'], ['login' => 'second']]], TaskProviderKind::GitHub);

        $this->assertSame('first', $issue->assignee);
    }

    public function test_gitlab_assignee_comes_from_username(): void
    {
        $this->assertSame('alice', ExternalIssue::fromProvider(['iid' => 3, 'assignee' => ['username' => 'alice']], TaskProviderKind::GitLab)->assignee);
        $this->assertSame('bob', ExternalIssue::fromProvider(['iid' => 3, 'assignees' => [['username' => 'bob']]], TaskProviderKind::GitLab)->assignee);
    }

    public function test_linear_assignee_comes_from_email(): void
    {
        $issue = ExternalIssue::fromProvider(['id' => 'uuid', 'assignee' => ['email' => 'alice@example.com', 'name' => 'Alice']], TaskProviderKind::Linear);

        $this->assertSame('alice@example.com', $issue->assignee);
    }

    public function test_assignee_is_null_when_unassigned(): void
    {
        $this->assertNull(ExternalIssue::fromProvider(['number' => 1, 'assignee' => null, 'assignees' => []], TaskProviderKind::GitHub)->assignee);
        $this->assertNull(ExternalIssue::fromProvider(['iid' => 1], TaskProviderKind::GitLab)->assignee);
    }

    public function test_constructor_defaults_assignee_to_null(): void
    {
        $issue = new ExternalIssue('1', 't', 'b', 'u', 'open', []);

        $this->assertNull($issue->assignee);
    }
}
