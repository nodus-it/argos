<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\AgentName;
use App\Jobs\BuildWorkerImageJob;
use App\Models\WorkerStack;
use App\Workers\Compose\WorkerImageBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BuildWorkerImageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_resolves_and_dispatches_to_builder(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);

        $builder = Mockery::mock(WorkerImageBuilder::class);
        $builder->shouldReceive('build')
            ->once()
            ->withArgs(function ($resolved) use ($stack): bool {
                return $resolved->stack->id === $stack->id
                    && $resolved->agent->name === AgentName::ClaudeCode
                    && str_starts_with($resolved->stackTag, 'argos-stack:')
                    && str_starts_with($resolved->workerTag, 'argos-worker:');
            });
        $this->app->instance(WorkerImageBuilder::class, $builder);

        $job = new BuildWorkerImageJob($stack->id, AgentName::ClaudeCode);
        $job->handle(app(WorkerImageResolver::class), $builder);
    }

    public function test_job_throws_for_missing_stack(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $job = new BuildWorkerImageJob('01J0000000000000000000NONE', AgentName::ClaudeCode);
        $job->handle(app(WorkerImageResolver::class), app(WorkerImageBuilder::class));
    }
}
