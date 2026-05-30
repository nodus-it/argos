<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\TaskProviderKind;
use App\Enums\WorkflowStatus;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckConceptApprovalsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_checks_concept_review_links_that_have_a_comment_id(): void
    {
        // No reactions returned → nothing starts; we only assert which links
        // are picked up by the query.
        Http::fake(['https://api.github.com/*' => Http::response([])]);

        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'external_project_ref' => 'acme/widget',
        ]);

        // Eligible: in ConceptReview with a stored concept comment id.
        $this->linkFor(WorkflowStatus::ConceptReview, '555', $binding);
        // Ineligible: not in ConceptReview.
        $this->linkFor(WorkflowStatus::Draft, '777', $binding);
        // Ineligible: in ConceptReview but no concept comment id.
        $this->linkFor(WorkflowStatus::ConceptReview, null, $binding);

        $this->artisan('argos:check-concept-approvals')
            ->expectsOutputToContain('Checked 1 concept comment')
            ->assertSuccessful();
    }

    private function linkFor(WorkflowStatus $status, ?string $commentId, TaskProviderBinding $binding): void
    {
        $task = Task::factory()->create(['workflow_status' => $status]);
        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => (string) random_int(1, 9999),
            'concept_comment_id' => $commentId,
        ]);
    }
}
