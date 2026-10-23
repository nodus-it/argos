<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\WorkerStack;
use App\Models\Workspace;
use App\Services\Demo\DemoImageBuilder;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoImageBuilderTest extends TestCase
{
    public function test_tag_is_repository_plus_content_hash(): void
    {
        config()->set('argos.preview.default_image', 'argos-demo');

        config()->set('argos.compose.default_stack', 'php-8.5');

        $this->assertMatchesRegularExpression('/^argos-demo:8\.5-[a-f0-9]{8}$/', app(DemoImageBuilder::class)->tag());
        $this->assertMatchesRegularExpression('/^argos-demo:8\.4-[a-f0-9]{8}$/', app(DemoImageBuilder::class)->tag('8.4'));
    }

    public function test_php_version_follows_the_workspace_stack(): void
    {
        config()->set('argos.compose.default_stack', 'php-8.5');
        $builder = app(DemoImageBuilder::class);
        $workspace = fn (?string $stack): Workspace => (new Workspace)->setRelation('workerStack', $stack === null ? null : new WorkerStack(['name' => $stack]));

        $this->assertSame('8.4', $builder->phpVersionFor($workspace('php-8.4')));
        $this->assertSame('8.5', $builder->phpVersionFor($workspace('node-22')));
        $this->assertSame('8.5', $builder->phpVersionFor($workspace(null)));
        $this->assertSame('8.5', $builder->phpVersionFor(null));
    }

    public function test_tag_honours_configured_repository_name(): void
    {
        config()->set('argos.preview.default_image', 'my-registry/argos-demo');

        $this->assertStringStartsWith('my-registry/argos-demo:', app(DemoImageBuilder::class)->tag());
    }

    public function test_ensure_builds_when_image_is_missing(): void
    {
        $builder = new ScriptedDemoImageBuilder([
            ['cmd' => 'image inspect', 'exit' => 1],   // not present
            ['cmd' => 'build', 'exit' => 0],           // build succeeds
        ]);

        $tag = $builder->ensure('8.4');

        $this->assertMatchesRegularExpression('/^argos-demo:8\.4-[a-f0-9]{8}$/', $tag);
        $this->assertContains('build', array_column($builder->invoked, 'verb'));
        $this->assertContains('PHP_VERSION=8.4', $builder->buildCommand);
    }

    public function test_ensure_skips_build_when_image_present(): void
    {
        $builder = new ScriptedDemoImageBuilder([
            ['cmd' => 'image inspect', 'exit' => 0],   // already present
        ]);

        $builder->ensure();

        $this->assertNotContains('build', array_column($builder->invoked, 'verb'));
    }

    public function test_ensure_throws_on_build_failure(): void
    {
        $builder = new ScriptedDemoImageBuilder([
            ['cmd' => 'image inspect', 'exit' => 1],
            ['cmd' => 'build', 'exit' => 1, 'stderr' => 'boom'],
        ]);

        $this->expectException(RuntimeException::class);
        $builder->ensure();
    }
}

class ScriptedDemoImageBuilder extends DemoImageBuilder
{
    /** @var list<array{cmd: string, exit: int, stderr?: string}> */
    private array $script;

    /** @var list<array{verb: string}> */
    public array $invoked = [];

    /** @var list<string> */
    public array $buildCommand = [];

    /** @param  list<array{cmd: string, exit: int, stderr?: string}>  $script */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    protected function newProcess(array $cmd): Process
    {
        $this->invoked[] = ['verb' => in_array('build', $cmd, true) ? 'build' : 'inspect'];
        if (in_array('build', $cmd, true)) {
            $this->buildCommand = $cmd;
        }

        $next = array_shift($this->script) ?? ['cmd' => '', 'exit' => 0];

        return new ScriptedDemoProcess($next['exit'], $next['stderr'] ?? '');
    }
}

class ScriptedDemoProcess extends Process
{
    public function __construct(private readonly int $exitCode, private readonly string $stderr)
    {
        parent::__construct(['true']);
    }

    public function run(?callable $callback = null, array $env = []): int
    {
        return $this->exitCode;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function getErrorOutput(): string
    {
        return $this->stderr;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function setTimeout(?float $timeout): static
    {
        return $this;
    }
}
