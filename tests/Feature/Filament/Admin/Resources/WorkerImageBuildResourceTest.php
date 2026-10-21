<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\AgentName;
use App\Filament\Admin\Resources\WorkerImageBuildResource;
use App\Filament\Admin\Resources\WorkerImageBuildResource\Pages\ListWorkerImageBuilds;
use App\Filament\Admin\Resources\WorkerImageBuildResource\Pages\ViewWorkerImageBuild;
use App\Jobs\BuildWorkerImageJob;
use App\Models\User;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkerImageBuildResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_create_is_disabled(): void
    {
        $this->assertFalse(WorkerImageBuildResource::canCreate());
    }

    public function test_list_renders_existing_builds(): void
    {
        WorkerImageBuild::factory()->count(3)->create();

        Livewire::test(ListWorkerImageBuilds::class)
            ->assertSuccessful();
    }

    public function test_view_page_renders(): void
    {
        $build = WorkerImageBuild::factory()->ready()->create();

        Livewire::test(ViewWorkerImageBuild::class, ['record' => $build->getKey()])
            ->assertSuccessful();
    }

    public function test_rebuild_action_dispatches_build_job(): void
    {
        Queue::fake();
        $build = WorkerImageBuild::factory()->ready()->create();

        Livewire::test(ListWorkerImageBuilds::class)
            ->callAction(TestAction::make('rebuild')->table($build))
            ->assertNotified();

        Queue::assertPushed(BuildWorkerImageJob::class, function (BuildWorkerImageJob $job) use ($build): bool {
            return $job->workerStackId === $build->worker_stack_id
                && $job->agentName === $build->agent_name;
        });
    }

    public function test_outdated_scope_matches_stack_drift_only(): void
    {
        $stack = WorkerStack::factory()->create([
            'dockerfile_body' => "FROM php:8.4\n",
        ]);
        $currentHash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);

        // Stack drift: stack_hash != current
        $stackDrift = WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'deadbeef',
            'built_at' => now(),
        ]);

        // Current: stack_hash matches — the build age does not matter
        // (agent version checks are gone).
        $current = WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => $currentHash,
            'built_at' => now()->subDay(),
        ]);

        $outdatedIds = WorkerImageBuild::query()->outdated()->pluck('id')->all();

        $this->assertContains($stackDrift->id, $outdatedIds);
        $this->assertNotContains($current->id, $outdatedIds);

        $this->assertTrue($stackDrift->isOutdated());
        $this->assertFalse($current->isOutdated());
    }

    public function test_outdated_scope_excludes_builds_without_built_at(): void
    {
        $stack = WorkerStack::factory()->create();
        $currentHash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);

        $failed = WorkerImageBuild::factory()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => $currentHash,
            'built_at' => null,   // failed build, never finished
        ]);

        $this->assertFalse($failed->isOutdated());
        $this->assertNotContains($failed->id, WorkerImageBuild::query()->outdated()->pluck('id')->all());
    }

    public function test_rebuild_all_outdated_dispatches_one_job_per_unique_pair(): void
    {
        Queue::fake();

        $stack = WorkerStack::factory()->create();

        // Stack drift for the same (stack × agent) repeated twice — different
        // historical stack hashes, both stale. The bulk action must dedupe to
        // ONE job per pair, because the resolver hashes the current
        // dockerfile_body and would collide otherwise.
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'aaaaaaaa',
            'built_at' => now(),
        ]);
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'bbbbbbbb',
            'built_at' => now(),
        ]);
        // Different stack, also stale → second job
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => WorkerStack::factory()->create()->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'cccccccc',
            'built_at' => now(),
        ]);

        Livewire::test(ListWorkerImageBuilds::class)
            ->callAction(TestAction::make('rebuildAllOutdated')->table())
            ->assertNotified();

        // 2 unique pairs: (stack, claude-code) and (other stack, claude-code)
        Queue::assertPushed(BuildWorkerImageJob::class, 2);
    }

    public function test_rebuild_all_outdated_action_hidden_when_nothing_outdated(): void
    {
        $stack = WorkerStack::factory()->create();
        $currentHash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);

        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'stack_hash' => $currentHash,
            'built_at' => now(),
        ]);

        Livewire::test(ListWorkerImageBuilds::class)
            ->assertTableActionHidden('rebuildAllOutdated');
    }
}
