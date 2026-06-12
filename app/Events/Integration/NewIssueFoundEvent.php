<?php

declare(strict_types=1);

namespace App\Events\Integration;

use App\Events\DomainEvent;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;

/**
 * Fired the first time a matching external issue is ingested and turned into a
 * Task. Deliberately a fan-out/audit/extension seam — NOT the import mechanism:
 * the core action (creating the Task, idempotently, behind the ExternalIssueLink
 * dedup) stays an explicit path in IssueIngestService, so ordering, idempotency
 * and error handling don't depend on listeners. Listeners may react (notify,
 * audit, …); none are required in the OSS core.
 */
final class NewIssueFoundEvent extends DomainEvent
{
    public function __construct(
        public readonly TaskProviderBinding $binding,
        public readonly ExternalIssue $issue,
        public readonly Task $task,
    ) {
        parent::__construct();
    }
}
