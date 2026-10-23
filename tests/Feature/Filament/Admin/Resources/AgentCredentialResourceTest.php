<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages\CreateAgentCredential;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
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
        $this->actingAs(User::factory()->admin()->create());
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
}
