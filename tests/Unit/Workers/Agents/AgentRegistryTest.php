<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Agents;

use App\Enums\AgentName;
use App\Workers\Agents\AgentRegistry;
use App\Workers\Agents\AgentRunner;
use App\Workers\Agents\AgentSpec;
use App\Workers\Agents\ClaudeCodeRunner;
use InvalidArgumentException;
use Tests\TestCase;

class AgentRegistryTest extends TestCase
{
    public function test_registry_is_singleton(): void
    {
        $a = app(AgentRegistry::class);
        $b = app(AgentRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_default_registry_has_claude_code(): void
    {
        $registry = app(AgentRegistry::class);

        $this->assertTrue($registry->has(AgentName::ClaudeCode));
        $this->assertInstanceOf(ClaudeCodeRunner::class, $registry->get(AgentName::ClaudeCode));
    }

    public function test_get_throws_for_unregistered_name(): void
    {
        $registry = new AgentRegistry;

        $this->expectException(InvalidArgumentException::class);
        $registry->get(AgentName::ClaudeCode);
    }

    public function test_specs_returns_all_registered(): void
    {
        $registry = app(AgentRegistry::class);

        $specs = $registry->specs();
        $this->assertNotEmpty($specs);
        $this->assertInstanceOf(AgentSpec::class, $specs[0]);
    }

    public function test_register_picks_up_custom_runner(): void
    {
        $registry = new AgentRegistry;
        $registry->register(ClaudeCodeRunner::class);

        $this->assertContains(AgentName::ClaudeCode, $registry->names());
    }

    public function test_agent_name_resolves_to_runner_via_registry(): void
    {
        $runner = AgentName::ClaudeCode->runner();

        $this->assertInstanceOf(AgentRunner::class, $runner);
        $this->assertSame(AgentName::ClaudeCode, $runner::spec()->name);
    }

    public function test_agent_name_label_comes_from_spec(): void
    {
        $this->assertSame('Claude Code', AgentName::ClaudeCode->label());
    }
}
