<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Health;

use App\Enums\AgentName;
use App\Models\AgentVersion;
use App\Workers\Agents\AgentRegistry;
use App\Workers\Agents\ClaudeCodeRunner;
use App\Workers\Agents\CodexRunner;
use App\Workers\Health\AgentVersionCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentVersionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_persists_version_per_registered_agent(): void
    {
        $registry = new AgentRegistry;
        $registry->register(ClaudeCodeRunner::class);
        $registry->register(CodexRunner::class);

        $check = new FakeAgentVersionCheck($registry, [
            '@anthropic-ai/claude-code' => "1.5.0\n",
            '@openai/codex' => "0.130.0\n",
        ]);

        $report = $check->run();

        $this->assertSame('1.5.0', $report['claude-code']['upstream']);
        $this->assertSame('0.130.0', $report['codex']['upstream']);

        $row = AgentVersion::query()->find('claude-code');
        $this->assertSame('1.5.0', $row->upstream_version);
        $this->assertNotNull($row->last_checked_at);
    }

    public function test_latest_pin_always_signals_update_when_upstream_known(): void
    {
        $registry = new AgentRegistry;
        $registry->register(ClaudeCodeRunner::class);

        $check = new FakeAgentVersionCheck($registry, [
            '@anthropic-ai/claude-code' => "1.5.0\n",
        ]);

        $check->run();

        $this->assertTrue(AgentVersion::query()->find('claude-code')->has_update);
    }

    public function test_no_update_signal_when_npm_unreachable(): void
    {
        $registry = new AgentRegistry;
        $registry->register(ClaudeCodeRunner::class);

        $check = new FakeAgentVersionCheck($registry, [
            '@anthropic-ai/claude-code' => null,
        ]);

        $check->run();

        $row = AgentVersion::query()->find('claude-code');
        $this->assertNull($row->upstream_version);
        $this->assertFalse($row->has_update);
    }

    public function test_artisan_command_completes_successfully(): void
    {
        $registry = new AgentRegistry;
        $registry->register(ClaudeCodeRunner::class);
        $this->app->instance(AgentRegistry::class, $registry);

        $this->app->bind(AgentVersionCheck::class, fn () => new FakeAgentVersionCheck($registry, [
            '@anthropic-ai/claude-code' => "1.0.0\n",
        ]));

        $this->artisan('argos:check-agent-versions')->assertSuccessful();
        $this->assertNotNull(AgentVersion::query()->find('claude-code'));
    }

    public function test_check_one_returns_null_for_unregistered_agent(): void
    {
        $registry = new AgentRegistry; // empty
        $check = new FakeAgentVersionCheck($registry, []);

        $this->assertNull($check->checkOne(AgentName::ClaudeCode));
    }
}

/**
 * Test double — bypasses npm by reading scripted responses keyed by package.
 */
class FakeAgentVersionCheck extends AgentVersionCheck
{
    /**
     * @param  array<string, ?string>  $responses
     */
    public function __construct(AgentRegistry $registry, private array $responses)
    {
        parent::__construct($registry);
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        // cmd looks like ['npm', 'view', '<package>', 'version'].
        $package = $cmd[2] ?? '';
        $stdout = $this->responses[$package] ?? null;

        return new FakeProcess(
            exitCode: $stdout === null ? 1 : 0,
            stdout: $stdout ?? '',
        );
    }
}

class FakeProcess extends Process
{
    private bool $hasRun = false;

    public function __construct(
        private readonly int $exitCode,
        private readonly string $stdout,
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
