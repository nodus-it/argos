<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\DemoAccessMode;
use App\Enums\DemoStatus;
use App\Models\Demo;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use App\Services\Demo\DemoImageBuilder;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
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
        // No Traefik labels — routing is file-provider, not docker-provider.
        $this->assertStringNotContainsString('traefik', $yaml);

        // Both the edge network AND the pre-existing workspace volume must be
        // declared external at the top level, or `compose up` rejects the
        // project ("undefined volume").
        $parsed = Yaml::parse($yaml);
        $this->assertTrue($parsed['networks']['argos_edge']['external']);
        $this->assertTrue($parsed['volumes'][$task->volumeName()]['external']);

        // The external URL (incl. port) must be pinned so Laravel/Vite emit
        // browser-reachable asset URLs.
        $this->assertSame('http://demo-feat1.127.0.0.1.nip.io:8080', $parsed['services']['app']['environment']['APP_URL']);
        $this->assertSame('http://demo-feat1.127.0.0.1.nip.io:8080', $parsed['services']['app']['environment']['ASSET_URL']);

        // A throwaway APP_KEY is injected so Laravel boots even when the repo
        // ships no APP_KEY= line for `key:generate` (else MissingAppKeyException
        // → 500). Must be a valid base64 32-byte key.
        $appKey = $parsed['services']['app']['environment']['APP_KEY'];
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]+={0,2}$/', $appKey);
        $this->assertSame(32, strlen((string) base64_decode(substr($appKey, 7), true)));
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

    public function test_public_mode_writes_no_auth_middleware(): void
    {
        app(DemoDeployer::class)->writeTraefikRoute('demo-p', 8000, DemoAccessMode::Public);

        $parsed = Yaml::parseFile($this->traefikDir.'/demo-p.yml');
        $this->assertArrayNotHasKey('middlewares', $parsed['http']);
        $this->assertArrayNotHasKey('middlewares', $parsed['http']['routers']['demo-p']);
    }

    public function test_session_mode_adds_forward_auth_middleware(): void
    {
        config()->set('argos.preview.auth_gate_url', 'http://nginx:80/_argos/demo-gate');

        app(DemoDeployer::class)->writeTraefikRoute('demo-s', 8000, DemoAccessMode::Session);

        $parsed = Yaml::parseFile($this->traefikDir.'/demo-s.yml');
        $this->assertSame(['demo-s-auth'], $parsed['http']['routers']['demo-s']['middlewares']);

        $fwd = $parsed['http']['middlewares']['demo-s-auth']['forwardAuth'];
        $this->assertSame('http://nginx:80/_argos/demo-gate', $fwd['address']);
        $this->assertTrue($fwd['trustForwardHeader']);
    }

    public function test_basic_mode_adds_basic_auth_with_hashed_password(): void
    {
        config()->set('argos.preview.basic_user', 'demo');

        app(DemoDeployer::class)->writeTraefikRoute('demo-b', 8000, DemoAccessMode::Basic, 'secret-pw');

        $parsed = Yaml::parseFile($this->traefikDir.'/demo-b.yml');
        $users = $parsed['http']['middlewares']['demo-b-auth']['basicAuth']['users'];
        $this->assertCount(1, $users);

        [$user, $hash] = explode(':', $users[0], 2);
        $this->assertSame('demo', $user);
        $this->assertTrue(password_verify('secret-pw', $hash));
    }

    public function test_basic_mode_without_any_password_fails_closed(): void
    {
        config()->set('argos.preview.basic_password', null);

        $this->expectException(RuntimeException::class);
        app(DemoDeployer::class)->writeTraefikRoute('demo-x', 8000, DemoAccessMode::Basic, null);
    }

    public function test_apply_access_mode_rewrites_live_route_in_place(): void
    {
        $deployer = app(DemoDeployer::class);
        $task = Task::factory()->create(['name' => 'feat-live', 'demo_access_mode' => DemoAccessMode::Public]);
        Demo::factory()->for($task)->create(['status' => DemoStatus::Live, 'port' => 8000]);

        $file = $this->traefikDir.'/'.$deployer->demoSlug($task).'.yml';

        $deployer->applyAccessMode($task);
        $this->assertArrayNotHasKey('middlewares', Yaml::parseFile($file)['http']);

        $task->update(['demo_access_mode' => DemoAccessMode::Session]);
        $deployer->applyAccessMode($task);
        $this->assertSame(
            ['demo-feat-live-auth'],
            Yaml::parseFile($file)['http']['routers']['demo-feat-live']['middlewares'],
        );
    }

    public function test_apply_access_mode_recovers_port_from_existing_route_when_null(): void
    {
        $deployer = app(DemoDeployer::class);
        $task = Task::factory()->create(['name' => 'feat-legacy', 'demo_access_mode' => DemoAccessMode::Session]);
        $demo = Demo::factory()->for($task)->create(['status' => DemoStatus::Live, 'port' => null]);

        // An old route file written before the port column existed.
        $slug = $deployer->demoSlug($task);
        $deployer->writeTraefikRoute($slug, 8000, DemoAccessMode::Public);

        $deployer->applyAccessMode($task);

        $parsed = Yaml::parseFile($this->traefikDir.'/'.$slug.'.yml');
        $this->assertSame([$slug.'-auth'], $parsed['http']['routers'][$slug]['middlewares']);
        // The recovered port is backfilled for future rewrites.
        $this->assertSame(8000, $demo->fresh()->port);
    }

    public function test_apply_access_mode_is_noop_without_live_demo(): void
    {
        $deployer = app(DemoDeployer::class);
        $task = Task::factory()->create(['name' => 'feat-none']);

        $deployer->applyAccessMode($task);

        $this->assertFileDoesNotExist($this->traefikDir.'/'.$deployer->demoSlug($task).'.yml');
    }

    public function test_inherit_resolves_to_global_default(): void
    {
        config()->set('argos.preview.auth', 'session');
        $task = Task::factory()->create(['demo_access_mode' => DemoAccessMode::Inherit]);

        $this->assertSame(DemoAccessMode::Session, $task->effectiveDemoAccessMode());
    }

    public function test_deploy_happy_path_marks_live_and_writes_route(): void
    {
        $this->fakeContract();
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat1']);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
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

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
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

    public function test_deploy_evicts_oldest_demo_when_over_concurrency_cap(): void
    {
        config(['argos.preview.max_concurrent' => 1]);
        $this->fakeContract();
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat4']);

        // A running demo for a different task already occupies the only slot.
        $old = Demo::factory()->live()->create();

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'down', 'exit' => 0],   // teardown current (none yet)
            ['cmd' => 'down', 'exit' => 0],   // evict the old demo
            ['cmd' => 'up', 'exit' => 0],
            ['cmd' => 'exec -T app', 'exit' => 0],   // command
            ['cmd' => 'exec -T app', 'exit' => 0],   // health
        ]);

        $demo = $deployer->deploy($task);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame(DemoStatus::Stopped, $old->fresh()->status);
        $this->assertNull($old->fresh()->url);
    }

    public function test_deploy_uses_default_contract_when_repo_has_none(): void
    {
        // Neither contract file exists → the built-in default runtime kicks in.
        Http::fake([
            'api.github.com/repos/*' => Http::response('', 404),
        ]);
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat-default']);

        // down + up + 7 default commands + health = 10; pad to be safe.
        $script = array_fill(0, 12, ['cmd' => '', 'exit' => 0]);
        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, $script);

        $demo = $deployer->deploy($task);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame('http://demo-feat-default.127.0.0.1.nip.io:8080', $demo->url);

        // The placeholder in the bundled compose must be resolved to the runtime
        // image tag before `compose up`.
        $written = file_get_contents(sys_get_temp_dir().'/argos-demo-demo-feat-default/demo.compose.yml');
        $this->assertStringContainsString('argos-demo:testtag', (string) $written);
        $this->assertStringNotContainsString('__ARGOS_DEMO_IMAGE__', (string) $written);
    }

    public function test_repo_contract_resolves_builtin_runtime_placeholder(): void
    {
        // A repo contract may opt into Argos' built-in runtime by keeping the
        // __ARGOS_DEMO_IMAGE__ placeholder (Argos' own .argos/demo.* does this);
        // the deployer must resolve it just like for the default contract.
        $settings = <<<'YAML'
        entry:
          service: app
          port: 80
        workspace_mount: /var/www/html
        commands:
          - php artisan db:seed --class=FullDemoSeeder --force
        health:
          path: /
          timeout: 30
        YAML;

        Http::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.compose.yml*' => Http::response([
                'content' => base64_encode("services:\n  app:\n    image: __ARGOS_DEMO_IMAGE__\n"),
                'encoding' => 'base64',
            ]),
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => Http::response([
                'content' => base64_encode($settings),
                'encoding' => 'base64',
            ]),
        ]);

        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat-byo-contract']);

        $script = array_fill(0, 6, ['cmd' => '', 'exit' => 0]);
        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, $script);

        $demo = $deployer->deploy($task);

        $this->assertSame(DemoStatus::Live, $demo->status);

        $written = (string) file_get_contents(sys_get_temp_dir().'/argos-demo-demo-feat-byo-contract/demo.compose.yml');
        $this->assertStringContainsString('argos-demo:testtag', $written);
        $this->assertStringNotContainsString('__ARGOS_DEMO_IMAGE__', $written);
    }

    public function test_deploy_fails_when_contract_is_half_written(): void
    {
        // Exactly one file present is a mistake the author must see — no silent
        // fall-through to the default.
        Http::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.compose.yml*' => Http::response([
                'content' => base64_encode("services:\n  app:\n    image: php:8.4\n"),
                'encoding' => 'base64',
            ]),
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => Http::response('', 404),
        ]);
        $profile = $this->profile();
        $task = Task::factory()->for($profile, 'repoProfile')->create(['name' => 'feat-half']);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
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
    public function __construct(GitServiceFactory $factory, DemoImageBuilder $imageBuilder, array $script)
    {
        parent::__construct($factory, $imageBuilder);
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

/** Stubs the runtime image so the default-contract path never touches Docker. */
class FakeDemoImageBuilder extends DemoImageBuilder
{
    public function tag(): string
    {
        return 'argos-demo:testtag';
    }

    public function ensure(): string
    {
        return 'argos-demo:testtag';
    }

    public function imageExists(string $tag): bool
    {
        return true;
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
