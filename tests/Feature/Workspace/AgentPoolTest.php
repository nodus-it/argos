<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\User;
use App\Services\Workspace\AgentBusyException;
use App\Services\Workspace\AgentPool;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPoolTest extends TestCase
{
    use RefreshDatabase;

    private AgentPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = app(AgentPool::class);
    }

    public function test_usable_agents_are_own_and_shared_active_claude_agents(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $own = AgentCredential::factory()->ownedBy($me)->create(['name' => 'b-own']);
        $shared = AgentCredential::factory()->create(['name' => 'a-shared']);
        AgentCredential::factory()->ownedBy($other)->create();
        AgentCredential::factory()->ownedBy($me)->expired()->create();
        AgentCredential::factory()->ownedBy($me)->create(['agent_name' => AgentName::Codex, 'credentials' => ['x' => 1]]);
        AgentCredential::factory()->ownedBy($me)->create(['usage_limited_until' => now()->addHour()]);

        $ids = $this->pool->usableAgentsFor($me)->pluck('id')->all();

        // Own agents first, then shared ones.
        $this->assertSame([$own->id, $shared->id], $ids);
    }

    public function test_an_expired_usage_limit_makes_the_agent_usable_again(): void
    {
        $me = User::factory()->create();
        $agent = AgentCredential::factory()->ownedBy($me)->create(['usage_limited_until' => now()->subMinute()]);

        $this->assertTrue($this->pool->usableAgentsFor($me)->contains($agent));
    }

    public function test_acquire_respects_the_session_limit_and_is_idempotent(): void
    {
        $agent = AgentCredential::factory()->create(['max_concurrent_sessions' => 1]);
        $first = Thread::factory()->create(['agent_credential_id' => $agent->id]);
        $second = Thread::factory()->create(['agent_credential_id' => $agent->id]);

        $this->pool->acquire($first);
        $this->pool->acquire($first);
        $this->assertTrue($first->fresh()->session_open);
        $this->assertSame(1, $this->pool->activeSessions($agent));

        $this->expectException(AgentBusyException::class);
        $this->pool->acquire($second);
    }

    public function test_release_frees_the_slot(): void
    {
        $agent = AgentCredential::factory()->create(['max_concurrent_sessions' => 1]);
        $first = Thread::factory()->create(['agent_credential_id' => $agent->id]);
        $second = Thread::factory()->create(['agent_credential_id' => $agent->id]);

        $this->pool->acquire($first);
        $this->pool->release($first);
        $this->pool->acquire($second);

        $this->assertFalse($first->fresh()->session_open);
        $this->assertTrue($second->fresh()->session_open);
    }

    public function test_higher_limits_allow_parallel_sessions(): void
    {
        $agent = AgentCredential::factory()->create(['max_concurrent_sessions' => 2]);
        $threads = Thread::factory()->count(3)->create(['agent_credential_id' => $agent->id]);

        $this->pool->acquire($threads[0]);
        $this->pool->acquire($threads[1]);

        $this->expectException(AgentBusyException::class);
        $this->pool->acquire($threads[2]);
    }

    public function test_usage_limited_or_inactive_agents_cannot_be_acquired(): void
    {
        $limited = AgentCredential::factory()->create(['usage_limited_until' => now()->addHour()]);
        $revoked = AgentCredential::factory()->revoked()->create();

        foreach ([$limited, $revoked] as $agent) {
            $thread = Thread::factory()->create(['agent_credential_id' => $agent->id]);
            try {
                $this->pool->acquire($thread);
                $this->fail('acquire should have thrown');
            } catch (AgentBusyException) {
                $this->assertFalse($thread->fresh()->session_open);
            }
        }
    }

    public function test_mark_usage_limited_uses_the_reset_time_or_a_default(): void
    {
        $agent = AgentCredential::factory()->create();
        $reset = CarbonImmutable::now()->addHours(3)->startOfMinute();

        $this->pool->markUsageLimited($agent, $reset);
        $this->assertTrue($agent->fresh()->usage_limited_until->equalTo($reset));

        $this->pool->markUsageLimited($agent, null);
        $this->assertTrue($agent->fresh()->usage_limited_until->between(now()->addMinutes(59), now()->addMinutes(61)));

        $this->pool->markUsageLimited($agent, CarbonImmutable::now()->subHour());
        $this->assertTrue($agent->fresh()->usage_limited_until->isFuture());
    }
}
