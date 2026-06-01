<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\DemoStatus;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoDeployerTest extends TestCase
{
    use RefreshDatabase;

    private string $traefikDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traefikDir = sys_get_temp_dir().'/argos-test-traefik-'.uniqid();
        config()->set('argos.preview.traefik_dir', $this->traefikDir);
        config()->set('argos.preview.base_domain', '127.0.0.1.nip.io');
        config()->set('argos.preview.scheme', 'http');
        config()->set('argos.preview.port', 8080);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->traefikDir)) {
            array_map('unlink', glob($this->traefikDir.'/*') ?: []);
            @rmdir($this->traefikDir);
        }

        parent::tearDown();
    }

    private function profile(): RepoProfile
    {
        return RepoProfile::factory()->create([
            'platform' => 'github',
            'url' => 'https://github.com/acme/widget',
            'token' => 'ghp-test',
            'default_branch' => 'main',
            'live_demo_enabled' => true,
        ]);
    }

    private function fakeContract(): void
    {
        $settings = <<<'YAML'
        entry:
          service: app
          port: 8000
        workspace_mount: /var/www/html
        commands:
          - composer install --no-interaction
        health:
          path: /
          timeout: 30
        YAML;

        Http::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.compose.yml*' => Http::response([
                'content' => base64_encode("services:\n  app:\n    image: php:8.4\n"),
                'encoding' => 'base64',
            ]),
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => Http::response([
                'content' => base64_encode($settings),
                'encoding' => 'base64',
            ]),
        ]);
    }

    public function test_demo_slug_is_dns_safe(): void
    {
        $deployer = app(DemoDeployer::class);
        $task = Task::factory()->create(['name' => 'Task_ABC.123']);

        $this->assertSame('demo-task-abc-123', $deployer->demoSlug($task));
    }

    public function test_override_mounts_volume_and_joins_edge_network(): void
    {
        $deployer = app(DemoDeployer::class);
        $task = Task::factory()->create(['name' => 'feat1']);

        $yaml = $deployer->buildOverrideYaml($task, 'demo-feat1', [
            'service' => 'app',
            'port' => 8000,
            'workspace_mount' => '/var/www/html',
        ]);

        $this->assertStringContainsString($task->volumeName().':/var/www/html', $yaml);
        $this->assertStringContainsString('argos_edge', $yaml);
        $this->assertStringContainsString('demo-feat1', $yaml);
        $this->assertStringContainsString('external: true', $yaml);
        // No Traefik labels — routing is file-provider, not docker-provider.
        $this->assertStringNotContainsString('traefik', $yaml);
    }

    public function test_write_traefik_route_creates_file_and_returns_url_with_port(): void
    {
        $deployer = app(DemoDeployer::class);

        $url = $deployer->writeTraefikRoute('demo-feat1', 8000);

        $this->assertSame('http://demo-feat1.127.0.0.1.nip.io:8080', $url);

        $routeFile = $this->traefikDir.'/demo-feat1.yml';
        $this->assertFileExists($routeFile);
        $contents = file_get_contents($routeFile);
        $this->assertStringContainsString('Host(`demo-feat1.127.0.0.1.nip.io`)', $contents);
        $this->assertStringContainsString('http://demo-feat1:8000', $contents);
    }

    public function test_url_omits_standard_https_port(): void
    {
        config()->set('argos.preview.scheme', 'https');
        config()->set('argos.preview.port', 443);

        $url = app(DemoDeployer::class)->writeTraefikRoute('demo-x', 80);

        $this->assertSame('https://demo-x.127.0.0.1.nip.io', $url);
    }

    public function test_deploy_happy_path_marks_live_and_writes_route(): void
    {
        $this->fakeContract();
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat1']);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), [
            ['cmd' => 'docker compose -p demo-feat1 down', 'exit' => 0],   // initial teardown
            ['cmd' => 'docker compose -p demo-feat1 -f', 'exit' => 0, 'stdout' => "Container started\n"], // up
            ['cmd' => 'exec -T app', 'exit' => 0, 'stdout' => "composer ok\n"],  // command
            ['cmd' => 'exec -T app', 'exit' => 0],   // health probe
        ]);

        $demo = $deployer->deploy($task);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame('http://demo-feat1.127.0.0.1.nip.io:8080', $demo->url);
        $this->assertSame('demo-feat1', $demo->compose_project);
        $this->assertNotNull($demo->ttl_until);
        $this->assertFileExists($this->traefikDir.'/demo-feat1.yml');
        $this->assertStringContainsString('composer ok', $demo->build_log);
    }

    public function test_deploy_marks_failed_when_a_command_fails(): void
    {
        $this->fakeContract();
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat2']);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), [
            ['cmd' => 'down', 'exit' => 0],   // initial teardown
            ['cmd' => 'up', 'exit' => 0],     // up
            ['cmd' => 'exec -T app', 'exit' => 1, 'stderr' => 'composer failed'],  // command fails
            ['cmd' => 'down', 'exit' => 0],   // cleanup teardown
        ]);

        $demo = $deployer->deploy($task);

        $this->assertSame(DemoStatus::Failed, $demo->status);
        $this->assertNull($demo->url);
        $this->assertStringContainsString('Demo command failed', $demo->build_log);
        // No route written for a failed demo.
        $this->assertFileDoesNotExist($this->traefikDir.'/demo-feat2.yml');
    }

    public function test_deploy_fails_clearly_when_contract_missing(): void
    {
        Http::fake([
            'api.github.com/repos/*' => Http::response('', 404),
        ]);
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat3']);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), [
            ['cmd' => 'down', 'exit' => 0],   // initial teardown
            ['cmd' => 'down', 'exit' => 0],   // cleanup teardown
        ]);

        $demo = $deployer->deploy($task);

        $this->assertSame(DemoStatus::Failed, $demo->status);
        $this->assertStringContainsString('contract incomplete', strtolower($demo->build_log));
    }
}

/**
 * Test double: scripts newProcess() responses and no-ops the health-probe
 * sleep so deploy() runs without touching Docker or the clock.
 */
class FakeDemoDeployer extends DemoDeployer
{
    /** @var list<array{cmd: string, exit: int, stdout?: string, stderr?: string}> */
    private array $script;

    /** @var list<list<string>> */
    public array $invokedCommands = [];

    /**
     * @param  list<array{cmd: string, exit: int, stdout?: string, stderr?: string}>  $script
     */
    public function __construct(GitServiceFactory $factory, array $script)
    {
        parent::__construct($factory);
        $this->script = $script;
    }

    protected function sleep(int $seconds): void {}

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        $this->invokedCommands[] = $cmd;
        $joined = implode(' ', $cmd);

        // Match the next scripted response whose marker appears in the command;
        // fall back to the head of the queue so ordering stays explicit.
        $next = array_shift($this->script);
        if ($next === null) {
            throw new RuntimeException('FakeDemoDeployer: ran out of scripted responses; got '.$joined);
        }

        return new FakeDemoProcess(
            exitCode: $next['exit'],
            stdout: $next['stdout'] ?? '',
            stderr: $next['stderr'] ?? '',
        );
    }
}

/** Minimal Symfony Process replacement for the deployer's call surface. */
class FakeDemoProcess extends Process
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
        return $this->exitCode === 0;
    }

    public function getOutput(): string
    {
        return $this->stdout;
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
