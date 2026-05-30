<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkflowStatus;
use App\Models\ExternalIssueLink;
use App\Services\IssueTracker\ConceptApprovalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('argos:check-concept-approvals')]
#[Description('Poll concept comments of tasks awaiting review and start implement when an authorized 👍 reaction is present.')]
final class CheckConceptApprovalsCommand extends Command
{
    public function __construct(private readonly ConceptApprovalService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $links = ExternalIssueLink::query()
            ->whereNotNull('concept_comment_id')
            ->whereHas('task', fn ($q) => $q->where('workflow_status', WorkflowStatus::ConceptReview->value))
            ->with(['task', 'binding.connectedAccount'])
            ->get();

        $started = 0;
        foreach ($links as $link) {
            if ($this->service->check($link)) {
                $started++;
            }
        }

        $this->info("Checked {$links->count()} concept comment(s); started implement for {$started}.");

        return self::SUCCESS;
    }
}
