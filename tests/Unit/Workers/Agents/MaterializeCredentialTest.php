<?php

declare(strict_types=1);

namespace Tests\Unit\Workers\Agents;

use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Services\Anthropic\CredentialStore;
use App\Workers\Agents\ClaudeCodeRunner;
use App\Workers\Agents\CodexRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MaterializeCredentialTest extends TestCase
{
    use RefreshDatabase;

    // ─── ClaudeCodeRunner ───────────────────────────────────────────────────

    public function test_claude_runner_uses_db_credential_token_when_present(): void
    {
        $credential = AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'credentials' => ['token' => 'oat-from-db-1234'],
        ]);

        $env = (new ClaudeCodeRunner)->materializeCredential($credential)->envVars;

        $this->assertSame('oat-from-db-1234', $env['CLAUDE_CODE_OAUTH_TOKEN']);
    }

    public function test_claude_runner_falls_back_to_credential_store_file(): void
    {
        $store = Mockery::mock(CredentialStore::class);
        $store->shouldReceive('getClaudeToken')->andReturn('oat-from-store');
        $this->app->instance(CredentialStore::class, $store);

        $env = (new ClaudeCodeRunner)->materializeCredential(null)->envVars;

        $this->assertSame('oat-from-store', $env['CLAUDE_CODE_OAUTH_TOKEN']);
    }

    public function test_claude_runner_throws_when_nothing_configured(): void
    {
        $store = Mockery::mock(CredentialStore::class);
        $store->shouldReceive('getClaudeToken')->andReturn(null);
        $this->app->instance(CredentialStore::class, $store);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Claude OAuth Token/');
        (new ClaudeCodeRunner)->materializeCredential(null);
    }

    // ─── CodexRunner ────────────────────────────────────────────────────────

    public function test_codex_runner_encodes_credential_array_into_auth_json_env(): void
    {
        $authPayload = [
            'OPENAI_API_KEY' => null,
            'tokens' => ['access_token' => 'sk-codex-abc', 'refresh_token' => 'rt-xyz'],
            'last_refresh' => '2026-05-08T20:00:00Z',
        ];
        $credential = AgentCredential::factory()->create([
            'agent_name' => AgentName::Codex,
            'credentials' => $authPayload,
        ]);

        $env = (new CodexRunner)->materializeCredential($credential)->envVars;

        $this->assertArrayHasKey('CODEX_AUTH_JSON_CONTENT', $env);
        $decoded = json_decode($env['CODEX_AUTH_JSON_CONTENT'], true);
        $this->assertSame($authPayload, $decoded);
    }

    public function test_codex_runner_throws_when_credential_null(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Codex credential/');
        (new CodexRunner)->materializeCredential(null);
    }

    public function test_codex_runner_throws_when_credential_empty(): void
    {
        $credential = AgentCredential::factory()->create([
            'agent_name' => AgentName::Codex,
            'credentials' => [],
        ]);

        $this->expectException(RuntimeException::class);
        (new CodexRunner)->materializeCredential($credential);
    }
}
