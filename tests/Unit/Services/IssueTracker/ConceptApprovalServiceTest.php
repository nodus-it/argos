<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\ConceptApprovalService;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ConceptApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLink(array $linkOverrides = []): ExternalIssueLink
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'external_project_ref' => 'acme/widget',
        ]);

        return ExternalIssueLink::factory()->create(array_merge([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => '19',
            'concept_comment_id' => '555',
        ], $linkOverrides));
    }

    private function service(IssueTrackerContract $tracker): ConceptApprovalService
    {
        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('has')->andReturn(true);
        $registry->shouldReceive('make')->andReturn($tracker);

        return new ConceptApprovalService($registry, app(TaskService::class));
    }

    public function test_authorized_thumbs_up_starts_implement(): void
    {
        Bus::fake();
        $link = $this->makeLink();

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('getCommentReactions')
            ->andReturn([['emoji' => '+1', 'user_id' => '1', 'user_login' => 'maintainer']]);
        $tracker->shouldReceive('userCanApprove')->andReturn(true);

        $result = $this->service($tracker)->check($link);

        $this->assertTrue($result);
        Bus::assertDispatched(RunPhaseJob::class, fn (RunPhaseJob $j): bool => $j->phase === 'implement');
        $this->assertSame(WorkflowStatus::ImplementRunning, $link->task->fresh()->workflow_status);
    }

    public function test_thumbs_up_from_unauthorized_user_does_nothing(): void
    {
        Bus::fake();
        $link = $this->makeLink();

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('getCommentReactions')
            ->andReturn([['emoji' => '+1', 'user_id' => '2', 'user_login' => 'random-bystander']]);
        $tracker->shouldReceive('userCanApprove')->andReturn(false);

        $result = $this->service($tracker)->check($link);

        $this->assertFalse($result);
        Bus::assertNotDispatched(RunPhaseJob::class);
        $this->assertSame(WorkflowStatus::ConceptReview, $link->task->fresh()->workflow_status);
    }

    public function test_non_thumbs_up_reaction_is_ignored(): void
    {
        Bus::fake();
        $link = $this->makeLink();

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('getCommentReactions')
            ->andReturn([['emoji' => 'heart', 'user_id' => '1', 'user_login' => 'maintainer']]);
        $tracker->shouldNotReceive('userCanApprove');

        $result = $this->service($tracker)->check($link);

        $this->assertFalse($result);
        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    public function test_skips_task_not_in_concept_review(): void
    {
        Bus::fake();
        $link = $this->makeLink();
        $link->task->update(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldNotReceive('getCommentReactions');

        $result = $this->service($tracker)->check($link->fresh());

        $this->assertFalse($result);
        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    public function test_skips_link_without_concept_comment_id(): void
    {
        Bus::fake();
        $link = $this->makeLink(['concept_comment_id' => null]);

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldNotReceive('getCommentReactions');

        $result = $this->service($tracker)->check($link);

        $this->assertFalse($result);
        Bus::assertNotDispatched(RunPhaseJob::class);
    }
}
