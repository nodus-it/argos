<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Compose;

use App\Enums\AgentName;
use App\Enums\WorkerImageEntityStatus;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\WorkerStack;
use App\Workers\Compose\IncompatibleStackAgentException;
use App\Workers\Compose\WorkerImageBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkerImageResolverTest extends TestCase
{
    use RefreshDatabase;

    private WorkerImageResolver $resolver;

    private WorkerImageBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = Mockery::mock(WorkerImageBuilder::class);
        $this->resolver = new WorkerImageResolver($this->builder);
    }

    public function test_uses_task_override_first(): void
    {
        $defaultStack = WorkerStack::factory()->create(['name' => 'php-8.4', 'capabilities' => ['node']]);
        $overrideStack = WorkerStack::factory()->create(['name' => 'php-8.3', 'capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $defaultStack->id]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'worker_stack_id_override' => $overrideStack->id,
        ]);

        $resolved = $this->resolver->resolve($task);

        $this->assertSame($overrideStack->id, $resolved->stack->id);
    }

    public function test_falls_back_to_repo_profile_stack(): void
    {
        $stack = WorkerStack::factory()->create(['name' => 'php-8.4', 'capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $resolved = $this->resolver->resolve($task);

        $this->assertSame($stack->id, $resolved->stack->id);
    }

    public function test_falls_back_to_default_stack_when_profile_has_none(): void
    {
        $defaultStack = WorkerStack::factory()->create(['name' => 'php-8.4', 'capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => null]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        config(['argos.compose.default_stack' => 'php-8.4']);

        $resolved = $this->resolver->resolve($task);

        $this->assertSame($defaultStack->id, $resolved->stack->id);
    }

    public function test_default_agent_is_claude_code(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $resolved = $this->resolver->resolve($task);

        $this->assertSame(AgentName::ClaudeCode, $resolved->agent->name);
    }

    public function test_task_override_wins_over_profile_for_agent(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create([
            'worker_stack_id' => $stack->id,
            'worker_agent_name' => 'claude-code',
        ]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'worker_agent_name_override' => 'claude-code',
        ]);

        $resolved = $this->resolver->resolve($task);

        $this->assertSame(AgentName::ClaudeCode, $resolved->agent->name);
    }

    public function test_throws_when_stack_lacks_required_capabilities(): void
    {
        $stack = WorkerStack::factory()->create([
            'name' => 'python-3.12',
            'capabilities' => ['python', 'pip'],
        ]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $this->expectException(IncompatibleStackAgentException::class);
        $this->expectExceptionMessage("Stack 'python-3.12' lacks capabilities required by agent 'claude-code'");
        $this->resolver->resolve($task);
    }

    public function test_throws_when_stack_is_disabled(): void
    {
        $stack = WorkerStack::factory()->create([
            'capabilities' => ['node'],
            'status' => WorkerImageEntityStatus::Disabled,
        ]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is disabled');
        $this->resolver->resolve($task);
    }

    public function test_tag_is_deterministic_per_stack_and_agent(): void
    {
        $stack = WorkerStack::factory()->create([
            'name' => 'php-8.4',
            'capabilities' => ['node'],
            'dockerfile_body' => 'FROM php:8.4',
        ]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $a = $this->resolver->resolve($task);
        $b = $this->resolver->resolve($task);

        $this->assertSame($a->stackTag, $b->stackTag);
        $this->assertSame($a->workerTag, $b->workerTag);
        $this->assertMatchesRegularExpression('/^argos-stack:php-8\.4-[a-f0-9]{8}$/', $a->stackTag);
        // Tag now embeds two 8-hex hashes: stack-dockerfile-body, worker-lib-tree.
        $this->assertMatchesRegularExpression('/^argos-worker:php-8\.4-[a-f0-9]{8}-[a-f0-9]{8}-claude-code-latest$/', $a->workerTag);
    }

    public function test_dockerfile_body_change_changes_tag(): void
    {
        $stack = WorkerStack::factory()->create([
            'name' => 'php-8.4',
            'capabilities' => ['node'],
            'dockerfile_body' => 'FROM php:8.4',
        ]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);
        $tagBefore = $this->resolver->resolve($task)->workerTag;

        $stack->forceFill(['dockerfile_body' => "FROM php:8.4\nRUN apt-get install -y new-tool"])->save();
        $task->refresh();

        $tagAfter = $this->resolver->resolve($task->fresh())->workerTag;

        $this->assertNotSame($tagBefore, $tagAfter);
    }

    public function test_worker_lib_change_changes_tag(): void
    {
        // Synthetic tree under storage/framework/testing/ — Laravel's
        // base_path() resolves the lib paths relative to repo root, so the
        // tree must live there. .gitignore covers storage/.
        $rel = 'storage/framework/testing/wir-'.bin2hex(random_bytes(4));
        $abs = base_path($rel);
        mkdir($abs.'/lib', 0o755, true);
        file_put_contents($abs.'/lib/mcp.sh', "v1\n");

        $resolver = $this->makeResolverWithLibPaths([$rel.'/lib']);

        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        try {
            $tagBefore = $resolver->resolve($task)->workerTag;
            file_put_contents($abs.'/lib/mcp.sh', "v2 with new line\n");
            $tagAfter = $resolver->resolve($task)->workerTag;

            $this->assertNotSame($tagBefore, $tagAfter, 'worker lib change must invalidate the image tag');
        } finally {
            @unlink($abs.'/lib/mcp.sh');
            @rmdir($abs.'/lib');
            @rmdir($abs);
        }
    }

    public function test_default_worker_lib_paths_all_exist(): void
    {
        // Guards against the fingerprint hashing a stale path (e.g. a removed
        // dockerfile): a missing path contributes nothing to the hash, so a
        // typo silently disables cache invalidation for the file it meant to
        // track. Every default path must resolve to a real file or directory.
        $resolver = new class($this->builder) extends WorkerImageResolver
        {
            /** @return list<string> */
            public function exposedWorkerLibPaths(): array
            {
                return $this->workerLibPaths();
            }
        };

        foreach ($resolver->exposedWorkerLibPaths() as $rel) {
            $abs = base_path($rel);
            $this->assertTrue(
                is_file($abs) || is_dir($abs),
                "workerLibPaths() references a non-existent path: {$rel}"
            );
        }
    }

    public function test_worker_lib_fingerprint_is_deterministic(): void
    {
        $rel = 'storage/framework/testing/wir-'.bin2hex(random_bytes(4));
        $abs = base_path($rel);
        mkdir($abs.'/lib', 0o755, true);
        file_put_contents($abs.'/lib/a.sh', "alpha\n");
        file_put_contents($abs.'/lib/b.sh', "beta\n");

        $resolver = $this->makeResolverWithLibPaths([$rel.'/lib']);

        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        try {
            $a = $resolver->resolve($task)->workerTag;
            $b = $resolver->resolve($task)->workerTag;
            $this->assertSame($a, $b);
        } finally {
            @unlink($abs.'/lib/a.sh');
            @unlink($abs.'/lib/b.sh');
            @rmdir($abs.'/lib');
            @rmdir($abs);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function makeResolverWithLibPaths(array $paths): WorkerImageResolver
    {
        return new class($this->builder, $paths) extends WorkerImageResolver
        {
            /** @param list<string> $paths */
            public function __construct(WorkerImageBuilder $builder, private readonly array $paths)
            {
                parent::__construct($builder);
            }

            protected function workerLibPaths(): array
            {
                return $this->paths;
            }
        };
    }

    public function test_resolve_or_build_skips_build_when_image_present(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $this->builder->shouldReceive('workerImageExists')->once()->andReturnTrue();
        $this->builder->shouldNotReceive('build');

        $tag = $this->resolver->resolveOrBuild($task);

        $this->assertStringStartsWith('argos-worker:', $tag);
    }

    public function test_resolve_or_build_invokes_builder_when_image_missing(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $this->builder->shouldReceive('workerImageExists')->once()->andReturnFalse();
        $this->builder->shouldReceive('build')->once();

        $this->resolver->resolveOrBuild($task);
    }
}
