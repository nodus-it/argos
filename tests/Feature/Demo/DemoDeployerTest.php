<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\DemoStatus;
use App\Models\Demo;
use App\Models\RepoProfile;
use App\Models\Workspace;
use App\Services\Demo\DemoComposeBuilder;
use App\Services\Demo\DemoDeployer;
use App\Services\Demo\DemoImageBuilder;
use App\Services\Demo\TraefikRouter;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
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

        Saloon::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.compose.yml*' => MockResponse::make([
                'content' => base64_encode("services:\n  app:\n    image: php:8.4\n"),
                'encoding' => 'base64',
            ]),
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => MockResponse::make([
                'content' => base64_encode($settings),
                'encoding' => 'base64',
            ]),
        ]);
    }

    public function test_override_mounts_volume_and_joins_edge_network(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'feat1']);

        $yaml = app(DemoComposeBuilder::class)->buildOverrideYaml($workspace, 'demo-feat1', [
            'service' => 'app',
            'port' => 8000,
            'workspace_mount' => '/var/www/html',
        ], 'http://demo-feat1.127.0.0.1.nip.io:8080');

        $this->assertStringContainsString($workspace->volumeName().':/var/www/html', $yaml);
        $this->assertStringContainsString('argos_edge', $yaml);
        $this->assertStringContainsString('demo-feat1', $yaml);
        // No Traefik labels — routing is file-provider, not docker-provider.
        $this->assertStringNotContainsString('traefik', $yaml);

        // Both the edge network AND the pre-existing workspace volume must be
        // declared external at the top level, or `compose up` rejects the
        // project ("undefined volume").
        $parsed = Yaml::parse($yaml);
        $this->assertTrue($parsed['networks']['argos_edge']['external']);
        $this->assertTrue($parsed['volumes'][$workspace->volumeName()]['external']);

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

        // A per-demo session cookie name, distinct from the parent's
        // `argos_session`, so an Argos-deployed-as-its-own-demo keeps its login
        // (the parent's leading-dot cookie would otherwise be sent here and fail
        // to decrypt under a different APP_KEY → session reset every request).
        $cookie = $parsed['services']['app']['environment']['SESSION_COOKIE'];
        $this->assertSame('demo_feat1_session', $cookie);
        $this->assertNotSame('argos_session', $cookie);

        // Marks the container as a live demo so the seeded app loads its full
        // demo profile (DatabaseSeeder → FullDemoSeeder) instead of admin-only.
        $this->assertSame('1', $parsed['services']['app']['environment']['ARGOS_DEMO']);
    }

    public function test_write_traefik_route_creates_file_and_returns_url_with_port(): void
    {
        $url = app(TraefikRouter::class)->writeRoute('demo-feat1', 8000);

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

        $url = app(TraefikRouter::class)->writeRoute('demo-x', 80);

        $this->assertSame('https://demo-x.127.0.0.1.nip.io', $url);
    }

    public function test_routes_are_always_guarded_by_the_workspace_gate(): void
    {
        config()->set('argos.workspaces.gate_url', 'http://nginx:80/_argos/workspace-gate');

        app(TraefikRouter::class)->writeRoute('demo-s', 8000);

        $parsed = Yaml::parseFile($this->traefikDir.'/demo-s.yml');
        $this->assertSame(['demo-s-auth'], $parsed['http']['routers']['demo-s']['middlewares']);

        $fwd = $parsed['http']['middlewares']['demo-s-auth']['forwardAuth'];
        $this->assertSame('http://nginx:80/_argos/workspace-gate', $fwd['address']);
        $this->assertTrue($fwd['trustForwardHeader']);
    }

    public function test_deploy_happy_path_marks_live_and_writes_route(): void
    {
        $this->fakeContract();
        $profile = $this->profile();
        $workspace = Workspace::factory()->create(['slug' => 'feat1', 'repo_profile_id' => $profile->id]);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'docker compose -p demo-ws-feat1 down', 'exit' => 0],   // initial teardown
            ['cmd' => 'docker compose -p demo-ws-feat1 -f', 'exit' => 0, 'stdout' => "Container started\n"], // up
            ['cmd' => 'exec -T app', 'exit' => 0, 'stdout' => "composer ok\n"],  // command
            ['cmd' => 'exec -T app', 'exit' => 0],   // health probe
        ]);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame('http://demo-ws-feat1.127.0.0.1.nip.io:8080', $demo->url);
        $this->assertSame('demo-ws-feat1', $demo->compose_project);
        $this->assertNotNull($demo->ttl_until);
        $this->assertFileExists($this->traefikDir.'/demo-ws-feat1.yml');
        $this->assertStringContainsString('composer ok', $demo->build_log);
    }

    public function test_deploy_marks_failed_when_a_command_fails(): void
    {
        $this->fakeContract();
        $profile = $this->profile();
        $workspace = Workspace::factory()->create(['slug' => 'feat2', 'repo_profile_id' => $profile->id]);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'down', 'exit' => 0],   // initial teardown
            ['cmd' => 'up', 'exit' => 0],     // up
            ['cmd' => 'exec -T app', 'exit' => 1, 'stderr' => 'composer failed'],  // command fails
            ['cmd' => 'down', 'exit' => 0],   // cleanup teardown
        ]);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Failed, $demo->status);
        $this->assertNull($demo->url);
        $this->assertStringContainsString('Demo command failed', $demo->build_log);
        // No route written for a failed demo.
        $this->assertFileDoesNotExist($this->traefikDir.'/demo-ws-feat2.yml');
    }

    public function test_deploy_evicts_oldest_demo_when_over_concurrency_cap(): void
    {
        config(['argos.preview.max_concurrent' => 1]);
        $this->fakeContract();
        $profile = $this->profile();
        $workspace = Workspace::factory()->create(['slug' => 'feat4', 'repo_profile_id' => $profile->id]);

        // A running demo of another workspace already occupies the only slot.
        $old = Demo::factory()->live()->create();

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'down', 'exit' => 0],   // teardown current (none yet)
            ['cmd' => 'down', 'exit' => 0],   // evict the old demo
            ['cmd' => 'up', 'exit' => 0],
            ['cmd' => 'exec -T app', 'exit' => 0],   // command
            ['cmd' => 'exec -T app', 'exit' => 0],   // health
        ]);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame(DemoStatus::Stopped, $old->fresh()->status);
        $this->assertNull($old->fresh()->url);
    }

    public function test_deploy_uses_default_contract_when_repo_has_none(): void
    {
        // Neither contract file exists → the built-in default runtime kicks in.
        Saloon::fake([
            'api.github.com/repos/*' => MockResponse::make('', 404),
        ]);
        $profile = $this->profile();
        $workspace = Workspace::factory()->create(['slug' => 'feat-default', 'repo_profile_id' => $profile->id]);

        // down + up + 7 default commands + health = 10; pad to be safe.
        $script = array_fill(0, 12, ['cmd' => '', 'exit' => 0]);
        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, $script);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame('http://demo-ws-feat-default.127.0.0.1.nip.io:8080', $demo->url);

        // The placeholder in the bundled compose must be resolved to the runtime
        // image tag before `compose up`.
        $written = file_get_contents(sys_get_temp_dir().'/argos-demo-demo-ws-feat-default/demo.compose.yml');
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

        Saloon::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.compose.yml*' => MockResponse::make([
                'content' => base64_encode("services:\n  app:\n    image: __ARGOS_DEMO_IMAGE__\n"),
                'encoding' => 'base64',
            ]),
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => MockResponse::make([
                'content' => base64_encode($settings),
                'encoding' => 'base64',
            ]),
        ]);

        $profile = $this->profile();
        $workspace = Workspace::factory()->create(['slug' => 'feat-byo-contract', 'repo_profile_id' => $profile->id]);

        $script = array_fill(0, 6, ['cmd' => '', 'exit' => 0]);
        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, $script);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Live, $demo->status);

        $written = (string) file_get_contents(sys_get_temp_dir().'/argos-demo-demo-ws-feat-byo-contract/demo.compose.yml');
        $this->assertStringContainsString('argos-demo:testtag', $written);
        $this->assertStringNotContainsString('__ARGOS_DEMO_IMAGE__', $written);
    }

    public function test_deploy_fails_when_contract_is_half_written(): void
    {
        // Exactly one file present is a mistake the author must see — no silent
        // fall-through to the default.
        Saloon::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.compose.yml*' => MockResponse::make([
                'content' => base64_encode("services:\n  app:\n    image: php:8.4\n"),
                'encoding' => 'base64',
            ]),
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => MockResponse::make('', 404),
        ]);
        $profile = $this->profile();
        $workspace = Workspace::factory()->create(['slug' => 'feat-half', 'repo_profile_id' => $profile->id]);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'down', 'exit' => 0],   // initial teardown
            ['cmd' => 'down', 'exit' => 0],   // cleanup teardown
        ]);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Failed, $demo->status);
        $this->assertStringContainsString('contract incomplete', strtolower($demo->build_log));
    }

    public function test_workspace_demos_mount_the_workspace_volume_and_are_members_only(): void
    {
        $this->fakeContract();
        $workspace = Workspace::factory()->create(['slug' => 'login', 'repo_profile_id' => $this->profile()->id]);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'down', 'exit' => 0],
            ['cmd' => 'up', 'exit' => 0],
            ['cmd' => 'exec', 'exit' => 0, 'stdout' => "composer ok\n"],
            ['cmd' => 'exec', 'exit' => 0],
        ]);

        $demo = $deployer->deploy($workspace);

        $this->assertSame(DemoStatus::Live, $demo->status);
        $this->assertSame($workspace->id, $demo->workspace_id);
        $this->assertSame('http://demo-ws-login.127.0.0.1.nip.io:8080', $demo->url);
        $this->assertSame($demo->id, $workspace->currentDemo()?->id);

        // Contract commands run as the agent user so the volume stays editable.
        $exec = collect($deployer->invokedCommands)->first(fn (array $c): bool => in_array('composer install --no-interaction', $c, true));
        $this->assertNotNull($exec);
        $this->assertSame(['--user', '1000:1000', '-e', 'HOME=/tmp'], array_slice($exec, 6, 4));

        $route = Yaml::parseFile($this->traefikDir.'/demo-ws-login.yml')['http'];
        $this->assertStringEndsWith('/_argos/workspace-gate', $route['middlewares']['demo-ws-login-auth']['forwardAuth']['address']);
    }

    public function test_workspace_override_mounts_the_workspace_volume(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'login']);

        $yaml = app(DemoComposeBuilder::class)->buildOverrideYaml($workspace, 'demo-ws-login', [
            'service' => 'app', 'port' => 8000, 'workspace_mount' => '/var/www/html',
        ], 'http://demo-ws-login.127.0.0.1.nip.io:8080');

        $this->assertStringContainsString('ws_login:/var/www/html', $yaml);
    }

    public function test_rerun_commands_appends_to_the_live_demo_log(): void
    {
        $this->fakeContract();
        $workspace = Workspace::factory()->create(['slug' => 'login', 'repo_profile_id' => $this->profile()->id]);
        Demo::factory()->create(['workspace_id' => $workspace->id, 'status' => DemoStatus::Live, 'build_log' => 'first build']);

        $deployer = new FakeDemoDeployer(app(GitServiceFactory::class), new FakeDemoImageBuilder, [
            ['cmd' => 'exec', 'exit' => 0, 'stdout' => "migrated\n"],
        ]);
        $demo = $deployer->rerunCommands($workspace);

        $this->assertStringStartsWith('first build', $demo->build_log);
        $this->assertStringContainsString('migrated', $demo->build_log);
        $this->assertSame(['docker', 'compose', '-p', 'demo-ws-login', 'exec', '-T', '--user', '1000:1000'], array_slice($deployer->invokedCommands[0], 0, 8));
    }

    public function test_rerun_requires_a_live_demo(): void
    {
        $workspace = Workspace::factory()->create();

        $this->expectException(RuntimeException::class);
        app(DemoDeployer::class)->rerunCommands($workspace);
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
