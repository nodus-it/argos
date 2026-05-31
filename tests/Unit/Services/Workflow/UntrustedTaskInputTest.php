<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workflow;

use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\Workflow\UntrustedTaskInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UntrustedTaskInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_wraps_description_in_markers_with_matching_nonce(): void
    {
        $task = Task::factory()->create(['description' => 'Build the thing.']);

        $wrapped = app(UntrustedTaskInput::class)->wrap($task);

        $this->assertStringContainsString('[BEGIN UNTRUSTED TASK DESCRIPTION', $wrapped);
        $this->assertStringContainsString('[END UNTRUSTED TASK DESCRIPTION', $wrapped);
        $this->assertStringContainsString('Build the thing.', $wrapped);

        // Same unguessable nonce in both markers (breakout protection).
        $this->assertSame(1, preg_match('/ref:([0-9a-f]{16})]\n/', $wrapped, $begin));
        $this->assertSame(1, preg_match('/\[END UNTRUSTED TASK DESCRIPTION · ref:([0-9a-f]{16})]$/', $wrapped, $end));
        $this->assertSame($begin[1], $end[1]);
    }

    public function test_marks_imported_task_as_external_untrusted(): void
    {
        $task = Task::factory()->create();
        $binding = TaskProviderBinding::factory()->create();
        ExternalIssueLink::factory()->create([
            'task_id' => $task->id,
            'task_provider_binding_id' => $binding->id,
        ]);

        $wrapped = app(UntrustedTaskInput::class)->wrap($task);

        $this->assertStringContainsString('source: external issue tracker', $wrapped);
    }

    public function test_marks_manual_task_as_operator_entered(): void
    {
        $task = Task::factory()->create();

        $wrapped = app(UntrustedTaskInput::class)->wrap($task);

        $this->assertStringContainsString('source: operator', $wrapped);
    }
}
