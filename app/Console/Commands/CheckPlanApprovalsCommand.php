<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExternalIssueLink;
use App\Services\IssueTracker\PlanApprovalService;
use Illuminate\Console\Command;

/** Polls 👍 reactions on plan comments of issue-started automatic runs. */
class CheckPlanApprovalsCommand extends Command
{
    protected $signature = 'argos:check-plan-approvals';

    protected $description = 'Approve waiting plans of issue-started automatic runs that got a 👍 on the issue';

    public function __construct(private readonly PlanApprovalService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $approved = 0;
        ExternalIssueLink::query()
            ->with('binding')
            ->whereNotNull('plan_comment_id')
            ->whereNotNull('workspace_id')
            ->each(function (ExternalIssueLink $link) use (&$approved): void {
                if ($this->service->check($link)) {
                    $approved++;
                }
            });

        $this->info("Approved {$approved} plan(s).");

        return self::SUCCESS;
    }
}
