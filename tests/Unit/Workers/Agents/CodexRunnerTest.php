<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Agents;

use App\Enums\AgentName;
use App\Workers\Agents\CodexRunner;
use Tests\TestCase;

class CodexRunnerTest extends TestCase
{
    public function test_spec_identifies_as_codex(): void
    {
        $spec = CodexRunner::spec();

        $this->assertSame(AgentName::Codex, $spec->name);
        $this->assertSame('OpenAI Codex', $spec->label);
        $this->assertSame('@openai/codex', $spec->npmPackage);
    }

    public function test_spec_requires_node_capability(): void
    {
        $spec = CodexRunner::spec();

        $this->assertContains('node', $spec->requiresStackCapabilities);
    }

    public function test_spec_install_script_path_is_relative(): void
    {
        $spec = CodexRunner::spec();

        $this->assertSame('agents/install-codex.sh', $spec->installScript);
    }

    public function test_spec_config_schema_exposes_model_field(): void
    {
        $spec = CodexRunner::spec();

        $this->assertArrayHasKey('fields', $spec->configSchema);
        $this->assertArrayHasKey('model', $spec->configSchema['fields']);
    }
}
