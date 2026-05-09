<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Compose;

use App\Enums\AgentName;
use App\Enums\WorkerImageBuildStatus;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use App\Workers\Compose\ResolvedWorkerImage;
use App\Workers\Compose\WorkerImageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkerImageBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_stack_build_when_already_present_and_records_ready(): void
    {
        $stack = WorkerStack::factory()->create([
            'name' => 'php-8.4',
            'capabilities' => ['node'],
            'dockerfile_body' => "FROM php:8.4\n",
        ]);
        $resolved = $this->resolved($stack);

        $builder = new FakeWorkerImageBuilder([
            // image inspect for stack tag — present
            ['cmd' => 'docker image inspect '.$resolved->stackTag, 'exit' => 0, 'stdout' => 'sha256:stack'],
            // worker build
            ['cmd' => 'docker build', 'exit' => 0, 'stdout' => "Successfully built worker\n"],
            // post-build validation
            ['cmd' => 'docker run', 'exit' => 0, 'stdout' => "ok bash\nok sh\nok jq\nok git\nok sed\nok grep\nok awk\nok curl\nok claude\n"],
            // image inspect for size
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => '1234567890'],
        ]);

        $build = $builder->build($resolved);

        $this->assertSame(WorkerImageBuildStatus::Ready, $build->status);
        $this->assertSame(1234567890, $build->size_bytes);
        $this->assertNotNull($build->built_at);
        $this->assertStringContainsString('already present', $build->build_log);
        $this->assertStringContainsString('[validate]', $build->build_log);
    }

    public function test_builds_stack_then_worker_when_stack_missing(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $resolved = $this->resolved($stack);

        $builder = new FakeWorkerImageBuilder([
            ['cmd' => 'docker image inspect', 'exit' => 1, 'stdout' => ''],   // stack missing
            ['cmd' => 'docker build',         'exit' => 0, 'stdout' => "Successfully built stack\n"],
            ['cmd' => 'docker build',         'exit' => 0, 'stdout' => "Successfully built worker\n"],
            ['cmd' => 'docker run',           'exit' => 0, 'stdout' => "ok claude\n"],   // validation
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => '999'],
        ]);

        $build = $builder->build($resolved);

        $this->assertSame(WorkerImageBuildStatus::Ready, $build->status);
        $this->assertStringContainsString('Successfully built stack', $build->build_log);
        $this->assertStringContainsString('Successfully built worker', $build->build_log);
    }

    public function test_marks_failed_and_rethrows_on_build_error(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $resolved = $this->resolved($stack);

        $builder = new FakeWorkerImageBuilder([
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => 'sha:exists'],   // stack present
            ['cmd' => 'docker build',         'exit' => 1, 'stderr' => 'pull denied'],  // worker build fails
        ]);

        try {
            $builder->build($resolved);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Worker image build failed', $e->getMessage());
        }

        $record = WorkerImageBuild::query()
            ->where('worker_stack_id', $stack->id)
            ->first();
        $this->assertNotNull($record);
        $this->assertSame(WorkerImageBuildStatus::Failed, $record->status);
        $this->assertStringContainsString('Worker image build failed', $record->build_log);
    }

    public function test_validation_failure_marks_build_failed(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $resolved = $this->resolved($stack);

        $builder = new FakeWorkerImageBuilder([
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => 'sha:exists'],   // stack present
            ['cmd' => 'docker build',         'exit' => 0, 'stdout' => "Successfully built worker\n"],
            ['cmd' => 'docker run',           'exit' => 1, 'stdout' => "ok bash\nMISSING jq\nok git\n"],   // validation fails
            ['cmd' => 'docker rmi',           'exit' => 0, 'stdout' => 'Untagged'],   // cleanup
        ]);

        try {
            $builder->build($resolved);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('validation failed', $e->getMessage());
            $this->assertStringContainsString('MISSING jq', $e->getMessage());
        }

        $record = WorkerImageBuild::query()
            ->where('worker_stack_id', $stack->id)
            ->first();
        $this->assertNotNull($record);
        $this->assertSame(WorkerImageBuildStatus::Failed, $record->status);
    }

    public function test_validation_failure_untags_the_invalid_image(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $resolved = $this->resolved($stack);

        $builder = new FakeWorkerImageBuilder([
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => 'sha:exists'],
            ['cmd' => 'docker build',         'exit' => 0, 'stdout' => 'Successfully built worker'],
            ['cmd' => 'docker run',           'exit' => 1, 'stdout' => "MISSING jq\n"],
            ['cmd' => 'docker rmi',           'exit' => 0, 'stdout' => 'Untagged'],
        ]);

        try {
            $builder->build($resolved);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // expected
        }

        // Last command must be the rmi for the validation-failed tag —
        // otherwise resolveOrBuild() would re-use the broken image on
        // the next phase run.
        $rmiCmd = end($builder->invokedCommands);
        $this->assertSame(['docker', 'rmi', '-f', $resolved->workerTag], $rmiCmd);
    }

    public function test_validation_checks_agent_cli_binary(): void
    {
        $stack = WorkerStack::factory()->create(['capabilities' => ['node']]);
        $resolved = $this->resolved($stack);

        $builder = new FakeWorkerImageBuilder([
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => 'sha:exists'],
            ['cmd' => 'docker build',         'exit' => 0, 'stdout' => 'ok'],
            ['cmd' => 'docker run',           'exit' => 0, 'stdout' => "ok claude\n"],
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => '1'],
        ]);

        $builder->build($resolved);

        // The validation command (3rd invocation, index 2) should mention
        // the claude binary check derived from AgentSpec::cliBinary.
        $validationCmd = implode(' ', $builder->invokedCommands[2]);
        $this->assertStringContainsString('claude', $validationCmd);
    }

    public function test_worker_image_exists_returns_inspect_status(): void
    {
        $builder = new FakeWorkerImageBuilder([
            ['cmd' => 'docker image inspect', 'exit' => 0, 'stdout' => 'sha:abc'],
            ['cmd' => 'docker image inspect', 'exit' => 1, 'stdout' => ''],
        ]);

        $this->assertTrue($builder->workerImageExists('argos-worker:any'));
        $this->assertFalse($builder->workerImageExists('argos-worker:nope'));
    }

    private function resolved(WorkerStack $stack): ResolvedWorkerImage
    {
        return new ResolvedWorkerImage(
            stack: $stack,
            agent: AgentName::ClaudeCode->spec(),
            stackTag: "argos-stack:{$stack->name}-deadbeef",
            workerTag: "argos-worker:{$stack->name}-deadbeef-claude-code-latest",
        );
    }
}

/**
 * Test double: replaces newProcess() with a scripted FakeProcess so we
 * can assert call sequences without invoking real Docker.
 */
class FakeWorkerImageBuilder extends WorkerImageBuilder
{
    /**
     * @var list<array{cmd: string, exit: int, stdout?: string, stderr?: string}>
     */
    private array $script;

    /**
     * @var list<list<string>>
     */
    public array $invokedCommands = [];

    /**
     * @param  list<array{cmd: string, exit: int, stdout?: string, stderr?: string}>  $script
     */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        $this->invokedCommands[] = $cmd;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new RuntimeException('FakeWorkerImageBuilder: ran out of scripted responses; got '.implode(' ', $cmd));
        }

        return new FakeProcess(
            exitCode: $next['exit'],
            stdout: $next['stdout'] ?? '',
            stderr: $next['stderr'] ?? '',
        );
    }
}

/**
 * Symfony Process replacement — implements just enough of the surface
 * the builder calls (run/isSuccessful/getOutput/getErrorOutput/getExitCode).
 */
class FakeProcess extends Process
{
    private bool $hasRun = false;

    public function __construct(
        private readonly int $exitCode,
        private readonly string $stdout,
        private readonly string $stderr,
    ) {
        parent::__construct(['true']);
    }

    public function run(?callable $callback = null, array $env = []): int
    {
        $this->hasRun = true;

        return $this->exitCode;
    }

    public function isSuccessful(): bool
    {
        $this->ensureRun();

        return $this->exitCode === 0;
    }

    public function getOutput(): string
    {
        $this->ensureRun();

        return $this->stdout;
    }

    public function getErrorOutput(): string
    {
        $this->ensureRun();

        return $this->stderr;
    }

    public function getExitCode(): ?int
    {
        $this->ensureRun();

        return $this->exitCode;
    }

    public function setTimeout(?float $timeout): static
    {
        return $this;
    }

    private function ensureRun(): void
    {
        if (! $this->hasRun) {
            throw new RuntimeException('FakeProcess accessed before run().');
        }
    }
}
