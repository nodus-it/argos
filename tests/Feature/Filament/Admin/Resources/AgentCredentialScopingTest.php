<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\AgentName;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages\CreateAgentCredential;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages\EditAgentCredential;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
use App\Models\AgentCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Credentials\CredentialVerification;
use App\Services\Credentials\CredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AgentCredentialScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Workspace::factory()->create();
        $verifier = Mockery::mock(CredentialVerifier::class);
        $verifier->shouldReceive('verifyClaudeToken')->andReturn(CredentialVerification::valid());
        $this->app->instance(CredentialVerifier::class, $verifier);
    }

    public function test_users_see_only_their_own_agents(): void
    {
        $me = User::factory()->create();
        $own = AgentCredential::factory()->ownedBy($me)->create();
        $shared = AgentCredential::factory()->create();
        $foreign = AgentCredential::factory()->ownedBy(User::factory()->create())->create();
        $this->actingAs($me);

        Livewire::test(ListAgentCredentials::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$shared, $foreign]);

        $this->get(route('filament.app.account.resources.agents.edit', $foreign))->assertNotFound();
    }

    public function test_admins_see_every_agent(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $agents = [
            AgentCredential::factory()->create(),
            AgentCredential::factory()->ownedBy(User::factory()->create())->create(),
        ];

        Livewire::test(ListAgentCredentials::class)->assertCanSeeTableRecords($agents);
    }

    public function test_a_user_creates_a_personal_claude_agent(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test(CreateAgentCredential::class)
            ->fillForm([
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => 'My subscription',
                'credentials.token' => 'oat-personal',
                'default_model' => 'claude-sonnet-4-6',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = AgentCredential::query()->where('name', 'My subscription')->firstOrFail();
        $this->assertSame($me->id, $agent->user_id);
        $this->assertSame(1, $agent->max_concurrent_sessions);
        $this->assertSame('claude-sonnet-4-6', $agent->default_model);
    }

    public function test_admins_assign_owner_and_session_limit(): void
    {
        $owner = User::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $agent = AgentCredential::factory()->create();

        Livewire::test(EditAgentCredential::class, ['record' => $agent->getKey()])
            ->fillForm(['user_id' => $owner->id, 'max_concurrent_sessions' => 3, 'credentials.token' => 'oat-x'])
            ->call('save')
            ->assertHasNoFormErrors();

        $agent->refresh();
        $this->assertSame($owner->id, $agent->user_id);
        $this->assertSame(3, $agent->max_concurrent_sessions);
    }
}
