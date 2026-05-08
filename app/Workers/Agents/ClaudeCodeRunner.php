<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Services\Anthropic\CredentialStore;
use RuntimeException;

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

    public function materializeCredential(?AgentCredential $credential): MaterializedAgentCredential
    {
        $token = $credential?->credentials['token'] ?? null;

        if ($token === null || $token === '') {
            // Legacy fallback for installations without a per-agent
            // AgentCredential row yet — keeps the pre-Step-5.5 behaviour
            // working until the UI lets users create one.
            $legacy = config('argos.claude_token') ?: app(CredentialStore::class)->getClaudeToken();
            if ($legacy === null || $legacy === '') {
                throw new RuntimeException(
                    'Kein Claude OAuth Token konfiguriert. Bitte CLAUDE_CODE_OAUTH_TOKEN setzen oder einen AgentCredential für claude-code anlegen.'
                );
            }
            $token = $legacy;
        }

        return new MaterializedAgentCredential([
            'CLAUDE_CODE_OAUTH_TOKEN' => $token,
        ]);
    }
}
