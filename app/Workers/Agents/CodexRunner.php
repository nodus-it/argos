<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;
use App\Models\AgentCredential;
use RuntimeException;

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
 *
 * Credential delivery: Codex needs ~/.codex/auth.json on the worker
 * filesystem. Rather than dealing with cross-container file mounts,
 * we pass the JSON-encoded contents of the file as
 * CODEX_AUTH_JSON_CONTENT env-var; the worker-entrypoint writes the
 * file and unsets the env before any phase script runs.
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
            cliBinary: 'codex',
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
            availableModels: [
                'gpt-5-codex' => 'GPT-5 Codex',
            ],
            defaultModelByPhase: [
                'concept' => 'gpt-5-codex',
                'implement' => 'gpt-5-codex',
                'commit-message' => 'gpt-5-codex',
            ],
        );
    }

    public function materializeCredential(?AgentCredential $credential): MaterializedAgentCredential
    {
        if ($credential === null || $credential->credentials === []) {
            throw new RuntimeException(
                'No Codex credential configured. Add an AgentCredential for codex (paste your ~/.codex/auth.json contents).'
            );
        }

        // The encrypted-array cast already gives us an associative array.
        // We re-encode it so the entrypoint can drop it on disk byte-for-byte.
        $authJson = json_encode(
            $credential->credentials,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return new MaterializedAgentCredential([
            'CODEX_AUTH_JSON_CONTENT' => $authJson,
        ]);
    }
}
