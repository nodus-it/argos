<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\WorkerImageEntityStatus;
use App\Jobs\BuildWorkerImageJob;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmBuiltinWorkerImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_build_for_each_active_builtin_stack(): void
    {
        Queue::fake();

        // Two built-ins, both compatible with claude-code/codex
        WorkerStack::factory()->builtin()->create(['name' => 'php-8.3', 'capabilities' => ['node']]);
        WorkerStack::factory()->builtin()->create(['name' => 'php-8.4', 'capabilities' => ['node']]);
        // A user stack — must be ignored even though it's compatible
        WorkerStack::factory()->create(['name' => 'rust-stable', 'capabilities' => ['node']]);

        $this->artisan('argos:warm-builtin-images')
            ->assertSuccessful();

        // 2 stacks × 2 registered agents = 4 jobs
        Queue::assertPushed(BuildWorkerImageJob::class, 4);
    }

    public function test_default_only_narrows_to_configured_default_stack(): void
    {
        Queue::fake();

        WorkerStack::factory()->builtin()->create(['name' => 'php-8.3', 'capabilities' => ['node']]);
        WorkerStack::factory()->builtin()->create(['name' => 'php-8.4', 'capabilities' => ['node']]);
        config(['argos.compose.default_stack' => 'php-8.4']);

        $this->artisan('argos:warm-builtin-images', ['--default-only' => true])
            ->assertSuccessful();

        Queue::assertPushed(BuildWorkerImageJob::class, 2);
        Queue::assertPushed(BuildWorkerImageJob::class, fn (BuildWorkerImageJob $j) => WorkerStack::find($j->workerStackId)?->name === 'php-8.4');
    }

    public function test_skips_disabled_builtin_stacks(): void
    {
        Queue::fake();

        WorkerStack::factory()->builtin()->create([
            'name' => 'php-8.3',
            'capabilities' => ['node'],
            'status' => WorkerImageEntityStatus::Disabled,
        ]);
        WorkerStack::factory()->builtin()->create([
            'name' => 'php-8.4',
            'capabilities' => ['node'],
        ]);

        $this->artisan('argos:warm-builtin-images')
            ->assertSuccessful();

        // Only php-8.4 × 2 agents
        Queue::assertPushed(BuildWorkerImageJob::class, 2);
    }

    public function test_warns_when_no_builtin_stacks_present(): void
    {
        Queue::fake();

        $this->artisan('argos:warm-builtin-images')
            ->assertSuccessful()
            ->expectsOutputToContain('argos:sync-builtin-images');

        Queue::assertNotPushed(BuildWorkerImageJob::class);
    }
}
