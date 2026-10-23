<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Models\WorkerStack;
use App\Workers\Agents\AgentSpec;
use App\Workers\Compose\ResolvedWorkerImage;
use App\Workers\Compose\WorkerImageBuilder;
use App\Workers\Compose\WorkspaceImageBuilder;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FakeWorkspaceImageBuilder extends WorkspaceImageBuilder
{
    /** @var list<list<string>> */
    public array $commands = [];

    public bool $exists = false;

    protected function newProcess(array $cmd): Process
    {
        $this->commands[] = $cmd;
        $inspect = ($cmd[1] ?? '') === 'image' && ($cmd[2] ?? '') === 'inspect';

        return new Process([$inspect && ! $this->exists ? 'false' : 'true']);
    }
}

class WorkspaceImageBuilderTest extends TestCase
{
    private function resolved(): ResolvedWorkerImage
    {
        $spec = (new \ReflectionClass(AgentSpec::class))->newInstanceWithoutConstructor();

        return new ResolvedWorkerImage(new WorkerStack, $spec, 'argos-stack:php-8.4-aaaa', 'argos-worker:php-8.4-aaaa-bbbb-claude_code-latest');
    }

    public function test_tag_reuses_the_worker_tag_and_appends_a_stable_fingerprint(): void
    {
        $builder = app(WorkspaceImageBuilder::class);
        $tag = $builder->tag($this->resolved());

        $this->assertMatchesRegularExpression('/^argos-workspace:php-8\.4-aaaa-bbbb-claude_code-latest-[a-f0-9]{8}$/', $tag);
        $this->assertSame($tag, $builder->tag($this->resolved()));
    }

    public function test_fingerprint_changes_with_the_editor_version(): void
    {
        $builder = app(WorkspaceImageBuilder::class);
        $before = $builder->fingerprint();
        config(['argos.workspaces.openvscode_version' => '9.9.9']);

        $this->assertNotSame($before, $builder->fingerprint());
    }

    public function test_cached_image_is_not_rebuilt(): void
    {
        $workers = Mockery::mock(WorkerImageBuilder::class);
        $workers->shouldNotReceive('build');
        $builder = new FakeWorkspaceImageBuilder($workers);
        $builder->exists = true;

        $this->assertSame('', $builder->ensure($this->resolved())['log']);
        $this->assertCount(1, $builder->commands);
    }

    public function test_builds_the_worker_image_first_then_the_layer(): void
    {
        $workers = Mockery::mock(WorkerImageBuilder::class);
        $workers->shouldReceive('workerImageExists')->andReturn(false);
        $workers->shouldReceive('build')->once();
        $builder = new FakeWorkspaceImageBuilder($workers);

        $result = $builder->ensure($this->resolved());

        $build = $builder->commands[1];
        $this->assertSame(['docker', 'build', '-t', $result['tag']], array_slice($build, 0, 4));
        $this->assertContains('BASE_IMAGE=argos-worker:php-8.4-aaaa-bbbb-claude_code-latest', $build);
        $this->assertStringEndsWith('.tools/docker/worker/workspace/Dockerfile', $build[5]);
    }
}
