<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AgentCredentialStatus;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\WorkerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_active_credential(): void
    {
        $credential = AgentCredential::factory()->create();

        $this->assertSame(AgentCredentialStatus::Active, $credential->status);
        $this->assertIsArray($credential->credentials);
        $this->assertArrayHasKey('token', $credential->credentials);
    }

    public function test_credentials_array_round_trips(): void
    {
        $credential = AgentCredential::factory()->create([
            'credentials' => ['token' => 'oat-secret', 'refresh_token' => 'rt-foo'],
        ]);

        $reloaded = AgentCredential::query()->find($credential->id);

        $this->assertSame('oat-secret', $reloaded->credentials['token']);
        $this->assertSame('rt-foo', $reloaded->credentials['refresh_token']);
    }

    public function test_credentials_are_encrypted_on_disk(): void
    {
        $credential = AgentCredential::factory()->create([
            'credentials' => ['token' => 'oat-supersecret'],
        ]);

        $raw = DB::table('agent_credentials')->where('id', $credential->id)->value('credentials');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('oat-supersecret', $raw);
    }

    public function test_agent_relation_loads(): void
    {
        $agent = WorkerAgent::factory()->create();
        $credential = AgentCredential::factory()->create(['worker_agent_id' => $agent->id]);

        $this->assertSame($agent->id, $credential->agent->id);
    }

    public function test_tasks_relation_loads(): void
    {
        $credential = AgentCredential::factory()->create();
        Task::factory()->count(2)->create(['agent_credential_id' => $credential->id]);

        $this->assertCount(2, $credential->tasks);
    }

    public function test_expired_state(): void
    {
        $credential = AgentCredential::factory()->expired()->create();

        $this->assertSame(AgentCredentialStatus::Expired, $credential->status);
    }

    public function test_revoked_state(): void
    {
        $credential = AgentCredential::factory()->revoked()->create();

        $this->assertSame(AgentCredentialStatus::Revoked, $credential->status);
    }
}
