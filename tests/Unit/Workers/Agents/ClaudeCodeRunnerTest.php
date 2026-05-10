<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Agents;

use App\Enums\AgentName;
use App\Workers\Agents\ClaudeCodeRunner;
use Tests\TestCase;

class ClaudeCodeRunnerTest extends TestCase
{
    public function test_spec_identifies_as_claude_code(): void
    {
        $spec = ClaudeCodeRunner::spec();

        $this->assertSame(AgentName::ClaudeCode, $spec->name);
        $this->assertSame('Claude Code', $spec->label);
        $this->assertSame('@anthropic-ai/claude-code', $spec->npmPackage);
    }

    public function test_spec_requires_node_capability(): void
    {
        $spec = ClaudeCodeRunner::spec();

        $this->assertContains('node', $spec->requiresStackCapabilities);
    }

    public function test_spec_install_script_path_is_relative(): void
    {
        $spec = ClaudeCodeRunner::spec();

        $this->assertSame('agents/install-claude-code.sh', $spec->installScript);
    }

    public function test_spec_config_schema_lists_optional_config_dir(): void
    {
        $spec = ClaudeCodeRunner::spec();

        $this->assertArrayHasKey('fields', $spec->configSchema);
        $this->assertArrayHasKey('config_dir', $spec->configSchema['fields']);
    }
}
