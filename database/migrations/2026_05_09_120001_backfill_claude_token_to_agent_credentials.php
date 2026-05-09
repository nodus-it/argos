<?php

declare(strict_types=1);

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Models\AgentCredential;
use Illuminate\Database\Migrations\Migration;

/**
 * Pre-Wave-1 installations carried a single Claude token in either the
 * ARGOS_CLAUDE_TOKEN env-var (read via config('argos.claude_token')) or
 * a file at config('argos.config_dir')/claude_token. Wave 1 introduces
 * per-agent AgentCredential rows; this migration carries that legacy
 * value into the new table so existing installations keep working without
 * a manual UI step.
 *
 * Idempotent: if an active claude-code credential already exists, no-op.
 * Non-destructive: never deletes the legacy file/env source — the runner
 * will keep reading it as a last-resort fallback for fresh checkouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = AgentCredential::query()
            ->where('agent_name', AgentName::ClaudeCode->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->exists();

        if ($existing) {
            return;
        }

        $token = $this->resolveLegacyToken();

        if ($token === null || $token === '') {
            return;
        }

        AgentCredential::create([
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'Migrated Claude Token',
            'credentials' => ['token' => $token],
            'status' => AgentCredentialStatus::Active,
        ]);
    }

    public function down(): void
    {
        AgentCredential::query()
            ->where('agent_name', AgentName::ClaudeCode->value)
            ->where('name', 'Migrated Claude Token')
            ->delete();
    }

    private function resolveLegacyToken(): ?string
    {
        $envToken = config('argos.claude_token');
        if (is_string($envToken) && $envToken !== '') {
            return $envToken;
        }

        $configDir = config('argos.config_dir');
        if (! is_string($configDir) || $configDir === '') {
            return null;
        }

        $path = rtrim($configDir, '/').'/claude_token';
        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return $contents !== '' ? $contents : null;
    }
};
