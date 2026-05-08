<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;

/**
 * OpenAI Codex CLI runner.
 *
 * Distribution: `npm install -g @openai/codex` (Node ≥ 18).
 * Auth: "Sign in with ChatGPT" or OPENAI_API_KEY.
 *
 * Output handling: Codex's `codex exec --json` emits its own newline-
 * delimited event stream which differs from Claude's. The Bash-side
 * runner (worker/lib/agents/codex.sh) is responsible for translating
 * the trailing event into the result-event shape phase scripts expect.
 */
final class CodexRunner implements AgentRunner
{
    public static function spec(): AgentSpec
    {
        return new AgentSpec(
            name: AgentName::Codex,
            label: 'OpenAI Codex',
            npmPackage: '@openai/codex',
            pinnedVersion: 'latest',
            installScript: 'agents/install-codex.sh',
            requiresStackCapabilities: ['node'],
            configSchema: [
                'fields' => [
                    'model' => [
                        'type' => 'string',
                        'optional' => true,
                        'description' => 'Codex model override (e.g. gpt-5-codex).',
                    ],
                ],
            ],
        );
    }
}
