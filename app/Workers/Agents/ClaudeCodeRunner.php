<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;

final class ClaudeCodeRunner implements AgentRunner
{
    public static function spec(): AgentSpec
    {
        return new AgentSpec(
            name: AgentName::ClaudeCode,
            label: 'Claude Code',
            npmPackage: '@anthropic-ai/claude-code',
            pinnedVersion: 'latest',
            installScript: 'agents/install-claude-code.sh',
            requiresStackCapabilities: ['node'],
            configSchema: [
                'fields' => [
                    'config_dir' => [
                        'type' => 'string',
                        'optional' => true,
                        'description' => 'Override CLAUDE_CONFIG_DIR (default: /workspace/.agent/claude-state)',
                    ],
                ],
            ],
        );
    }
}
