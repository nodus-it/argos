<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Compose;

use App\Models\WorkerStack;
use App\Workers\Agents\ClaudeCodeRunner;
use App\Workers\Compose\StackAgentCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StackAgentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_compatible_when_stack_provides_all_required_capabilities(): void
    {
        $stack = WorkerStack::factory()->make([
            'capabilities' => ['php', 'composer', 'node'],
        ]);

        $this->assertTrue(
            StackAgentCompatibility::isCompatible($stack, ClaudeCodeRunner::spec())
        );
        $this->assertSame([], StackAgentCompatibility::missingCapabilities($stack, ClaudeCodeRunner::spec()));
    }

    public function test_incompatible_when_required_capability_missing(): void
    {
        $stack = WorkerStack::factory()->make([
            'capabilities' => ['python', 'pip'],
        ]);

        $this->assertFalse(
            StackAgentCompatibility::isCompatible($stack, ClaudeCodeRunner::spec())
        );
        $this->assertSame(
            ['node'],
            StackAgentCompatibility::missingCapabilities($stack, ClaudeCodeRunner::spec()),
        );
    }

    public function test_missing_capabilities_handles_null_stack_capabilities(): void
    {
        $stack = WorkerStack::factory()->make(['capabilities' => null]);

        $this->assertSame(
            ['node'],
            StackAgentCompatibility::missingCapabilities($stack, ClaudeCodeRunner::spec()),
        );
    }
}
