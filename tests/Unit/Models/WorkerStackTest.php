<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\WorkerImageEntityStatus;
use App\Models\User;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerStackTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_with_defaults(): void
    {
        $stack = WorkerStack::factory()->create();

        $this->assertNotNull($stack->id);
        $this->assertSame(WorkerImageEntityStatus::Active, $stack->status);
        $this->assertFalse($stack->is_builtin);
        $this->assertFalse($stack->has_update);
    }

    public function test_array_casts_round_trip(): void
    {
        $stack = WorkerStack::factory()->create([
            'common_tools' => ['git', 'gh'],
            'capabilities' => ['php', 'composer'],
        ]);

        $reloaded = WorkerStack::query()->find($stack->id);

        $this->assertSame(['git', 'gh'], $reloaded->common_tools);
        $this->assertSame(['php', 'composer'], $reloaded->capabilities);
    }

    public function test_status_is_enum_cast(): void
    {
        $stack = WorkerStack::factory()->deprecated()->create();

        $this->assertSame(WorkerImageEntityStatus::Deprecated, $stack->status);
    }

    public function test_builtin_state_marks_is_builtin(): void
    {
        $stack = WorkerStack::factory()->builtin()->create();

        $this->assertTrue($stack->is_builtin);
    }

    public function test_created_by_relation_returns_user(): void
    {
        $user = User::factory()->create();
        $stack = WorkerStack::factory()->create(['created_by_user_id' => $user->id]);

        $this->assertNotNull($stack->createdBy);
        $this->assertSame($user->id, $stack->createdBy->id);
    }

    public function test_image_builds_relation(): void
    {
        $stack = WorkerStack::factory()->create();
        WorkerImageBuild::factory()->count(2)->create(['worker_stack_id' => $stack->id]);

        $this->assertCount(2, $stack->imageBuilds);
    }
}
