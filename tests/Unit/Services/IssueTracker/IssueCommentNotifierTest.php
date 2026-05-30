<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\IssueCommentNotifier;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IssueCommentNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_noop_when_task_has_no_external_link(): void
    {
        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldNotReceive('make');

        $notifier = new IssueCommentNotifier($registry);
        $task = Task::factory()->create();

        $notifier->notifyPhaseCompletion($task, 'implement', 'completed');

        $this->assertTrue(true);
    }

    public function test_posts_comment_when_link_exists(): void
    {
        $task = Task::factory()->create();

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('createComment')
            ->once()
            ->with('acme', 'widget', 42, Mockery::on(
                fn (string $body): bool => str_contains($body, 'Phase **Implement**')
                    && str_contains($body, '[Task in Argos öffnen](')
                    && str_contains($body, (string) $task->id),
            ));

        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('has')->andReturn(true);
        $registry->shouldReceive('make')->andReturn($tracker);

        $notifier = new IssueCommentNotifier($registry);

        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active,
            'external_project_ref' => 'acme/widget',
        ]);
        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => '42',
        ]);

        $notifier->notifyPhaseCompletion($task, 'implement', 'completed');
    }

    public function test_posts_comment_without_a_current_filament_panel(): void
    {
        // Reproduces the queue-worker condition: no Filament panel is current.
        // Building the task link via TaskResource::getUrl() throws there
        // ("No default Filament panel is set"), so the comment was silently
        // dropped. The named route must work regardless.
        Filament::setCurrentPanel(null);

        $task = Task::factory()->create();

        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('createComment')
            ->once()
            ->with('acme', 'widget', 7, Mockery::on(
                fn (string $body): bool => str_contains($body, 'Phase **Concept**')
                    && str_contains($body, "/admin/tasks/{$task->getKey()}"),
            ));

        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('has')->andReturn(true);
        $registry->shouldReceive('make')->andReturn($tracker);

        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'external_project_ref' => 'acme/widget',
        ]);
        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => '7',
        ]);

        (new IssueCommentNotifier($registry))->notifyPhaseCompletion($task, 'concept', 'completed');
    }

    public function test_swallows_exception_and_does_not_rethrow(): void
    {
        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('createComment')->andThrow(new \RuntimeException('API down'));

        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('has')->andReturn(true);
        $registry->shouldReceive('make')->andReturn($tracker);

        $notifier = new IssueCommentNotifier($registry);

        $task = Task::factory()->create();
        $binding = TaskProviderBinding::factory()->create([
            'external_project_ref' => 'acme/widget',
        ]);
        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => '1',
        ]);

        $notifier->notifyPhaseCompletion($task, 'implement', 'completed');

        $this->assertTrue(true, 'Exception must not propagate');
    }

    public function test_noop_when_provider_not_registered(): void
    {
        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldNotReceive('createComment');

        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('has')->andReturn(false);
        $registry->shouldNotReceive('make');

        $notifier = new IssueCommentNotifier($registry);

        $task = Task::factory()->create();
        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::Linear,
        ]);
        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
            'external_id' => '5',
        ]);

        $notifier->notifyPhaseCompletion($task, 'concept', 'completed');

        $this->assertTrue(true);
    }
}
