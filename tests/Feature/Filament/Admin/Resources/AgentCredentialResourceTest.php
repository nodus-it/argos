<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\CreateAgentCredential;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\EditAgentCredential;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
use App\Models\AgentCredential;
use App\Models\User;
use App\Services\Credentials\CredentialVerification;
use App\Services\Credentials\CredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AgentCredentialResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** Stub the on-save Claude token probe so these UI tests stay offline. */
    private function fakeVerifier(CredentialVerification $result): void
    {
        $verifier = Mockery::mock(CredentialVerifier::class);
        $verifier->shouldReceive('verifyClaudeToken')->andReturn($result);
        $this->app->instance(CredentialVerifier::class, $verifier);
    }

    public function test_list_page_renders(): void
    {
        AgentCredential::factory()->count(2)->create();

        Livewire::test(ListAgentCredentials::class)
            ->assertSuccessful();
    }

    public function test_create_persists_claude_token_credential(): void
    {
        $this->fakeVerifier(CredentialVerification::valid());

        Livewire::test(CreateAgentCredential::class)
            ->fillForm([
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => 'My Claude',
                'status' => AgentCredentialStatus::Active->value,
                'credentials.token' => 'oat-secret-1234',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cred = AgentCredential::query()->where('name', 'My Claude')->first();
        $this->assertNotNull($cred);
        $this->assertSame(AgentName::ClaudeCode, $cred->agent_name);
        $this->assertSame('oat-secret-1234', $cred->credentials['token']);
        $this->assertNotNull($cred->last_validated_at);
    }

    public function test_create_is_blocked_when_claude_token_rejected(): void
    {
        $this->fakeVerifier(CredentialVerification::rejected('invalid token'));

        Livewire::test(CreateAgentCredential::class)
            ->fillForm([
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => 'Bad Claude',
                'status' => AgentCredentialStatus::Active->value,
                'credentials.token' => 'oat-bad',
            ])
            ->call('create')
            ->assertNotified();

        $this->assertDatabaseMissing('agent_credentials', ['name' => 'Bad Claude']);
    }

    public function test_create_persists_codex_auth_json_from_textarea(): void
    {
        $authJson = json_encode([
            'OPENAI_API_KEY' => null,
            'tokens' => ['access_token' => 'sk-xyz', 'refresh_token' => 'rt-abc'],
            'last_refresh' => '2026-05-09T00:00:00Z',
        ]);

        Livewire::test(CreateAgentCredential::class)
            ->fillForm([
                'agent_name' => AgentName::Codex->value,
                'name' => 'My Codex',
                'status' => AgentCredentialStatus::Active->value,
                'credentials_json' => $authJson,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cred = AgentCredential::query()->where('name', 'My Codex')->first();
        $this->assertNotNull($cred);
        $this->assertSame(AgentName::Codex, $cred->agent_name);
        $this->assertSame('sk-xyz', $cred->credentials['tokens']['access_token']);
    }

    public function test_create_rejects_invalid_codex_json(): void
    {
        $this->expectException(\RuntimeException::class);

        Livewire::test(CreateAgentCredential::class)
            ->fillForm([
                'agent_name' => AgentName::Codex->value,
                'name' => 'Broken Codex',
                'status' => AgentCredentialStatus::Active->value,
                'credentials_json' => 'not-json{{{',
            ])
            ->call('create');
    }

    public function test_edit_codex_credential_round_trips_json(): void
    {
        $cred = AgentCredential::factory()->create([
            'agent_name' => AgentName::Codex,
            'name' => 'Codex original',
            'credentials' => ['tokens' => ['access_token' => 'sk-old']],
        ]);

        Livewire::test(EditAgentCredential::class, ['record' => $cred->getKey()])
            ->fillForm([
                'credentials_json' => json_encode([
                    'tokens' => ['access_token' => 'sk-new', 'refresh_token' => 'rt-new'],
                ]),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('sk-new', $cred->fresh()->credentials['tokens']['access_token']);
    }
}
