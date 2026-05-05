<?php

declare(strict_types=1);

namespace App\Services\IssueTracker\Contracts;

interface IssueTrackerContract
{
    /**
     * @param  array<string, mixed>  $filters  e.g. state, labels, assignee, milestone
     * @return array<int, array<string, mixed>>
     */
    public function listIssues(string $owner, string $project, array $filters = []): array;

    /**
     * Returns issue with content, comments, reactions and metadata combined.
     *
     * @return array<string, mixed>
     */
    public function getIssue(string $owner, string $project, int $issueNumber): array;

    /**
     * @param  array<string, mixed>  $options  e.g. labels, assignees, milestone
     * @return array<string, mixed>
     */
    public function createIssue(
        string $owner,
        string $project,
        string $title,
        string $body,
        array $options = [],
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function createComment(
        string $owner,
        string $project,
        int $issueNumber,
        string $body,
    ): array;
}
