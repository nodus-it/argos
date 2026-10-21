<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Broadcasting\ThreadStream;
use App\Enums\AutoThreadState;
use App\Enums\WorkspaceOrigin;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\AdvanceAutoThreadJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\ApiClient;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $bot;

    private RepoProfile $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoThreadJob::class]);
        Event::fake([ThreadStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->bot = User::factory()->create(['name' => 'CI Bot']);
        AgentCredential::factory()->ownedBy($this->bot)->create();
        $this->project = RepoProfile::factory()->create(['name' => 'widget']);
    }

    /** @param  list<string>  $abilities */
    private function clientToken(array $abilities = ['workspaces:read', 'workspaces:write'], ?User $runAs = null): string
    {
        return ApiClient::factory()->create(['run_as_user_id' => ($runAs ?? $this->bot)->id])->createToken('t', $abilities)->plainTextToken;
    }

    public function test_it_requires_a_token_with_the_ability(): void
    {
        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
        $this->withToken($this->clientToken(['projects:read']))->getJson('/api/v1/workspaces')->assertForbidden();
    }

    public function test_a_client_without_a_run_as_user_is_refused(): void
    {
        $token = ApiClient::factory()->create()->createToken('t', ['workspaces:write'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/workspaces', ['project' => 'widget', 'prompt' => 'Do it'])
            ->assertStatus(422)->assertJsonPath('code', 'no_actor');
    }

    public function test_it_starts_an_automatic_run_in_a_new_workspace(): void
    {
        $response = $this->withToken($this->clientToken())->postJson('/api/v1/workspaces', [
            'project' => 'widget', 'prompt' => 'Add priorities to notes', 'title' => 'Priorities', 'plan' => 'approve', 'ship' => 'draft_pr', 'max_turns' => 20,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.origin', 'api')
            ->assertJsonPath('data.project.name', 'widget')
            ->assertJsonPath('data.threads.0.auto.state', 'queued')
            ->assertJsonPath('data.threads.0.auto.plan', 'approve');

        $workspace = Workspace::query()->sole();
        $this->assertSame(WorkspaceOrigin::Api, $workspace->origin);
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($this->bot));
        $thread = Thread::query()->sole();
        $this->assertSame('draft_pr', $thread->auto_config['ship']);
        $this->assertSame(20, $thread->auto_config['max_turns']);
        Bus::assertDispatched(AdvanceAutoThreadJob::class, fn ($job): bool => $job->step === 'begin');
    }

    public function test_a_person_without_an_agent_gets_a_clear_error(): void
    {
        $nobody = User::factory()->create();

        $this->withToken($this->clientToken(runAs: $nobody))->postJson('/api/v1/workspaces', ['project' => 'widget', 'prompt' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'no_usable_agent');
        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_project_tokens_act_for_the_automation_user_and_stay_in_their_project(): void
    {
        $this->project->forceFill(['automation_user_id' => $this->bot->id])->save();
        $other = RepoProfile::factory()->create(['name' => 'other']);
        $token = $this->project->createToken('p', ['workspaces:read', 'workspaces:write'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/workspaces', ['project' => $this->project->id, 'prompt' => 'x'])->assertStatus(202);
        $this->withToken($token)->postJson('/api/v1/workspaces', ['project' => $other->id, 'prompt' => 'x'])->assertNotFound();

        $foreign = Workspace::factory()->create(['repo_profile_id' => $other->id]);
        WorkspaceMember::query()->create(['workspace_id' => $foreign->id, 'user_id' => $this->bot->id, 'role' => WorkspaceRole::Owner]);
        $this->withToken($token)->getJson('/api/v1/workspaces/'.$foreign->id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/workspaces')->assertJsonCount(1, 'data');
    }

    public function test_listing_shows_only_workspaces_of_the_acting_person(): void
    {
        $mine = Workspace::factory()->create(['repo_profile_id' => $this->project->id]);
        WorkspaceMember::query()->create(['workspace_id' => $mine->id, 'user_id' => $this->bot->id, 'role' => WorkspaceRole::Editor]);
        $theirs = Workspace::factory()->create(['repo_profile_id' => $this->project->id]);

        $token = $this->clientToken();
        $this->withToken($token)->getJson('/api/v1/workspaces')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->withToken($token)->getJson('/api/v1/workspaces/'.$theirs->id)->assertNotFound();
    }

    public function test_threads_can_be_paused_resumed_and_plans_need_a_waiting_plan(): void
    {
        $workspace = Workspace::factory()->create(['repo_profile_id' => $this->project->id, 'status' => WorkspaceStatus::Running]);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->bot->id, 'role' => WorkspaceRole::Owner]);
        $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $this->bot->id, 'auto_state' => AutoThreadState::Implementing, 'auto_config' => ['plan' => 'off'], 'auto_counters' => []]);
        $token = $this->clientToken();

        $this->withToken($token)->postJson("/api/v1/threads/{$thread->id}/pause")->assertOk()->assertJsonPath('data.auto.state', 'paused');
        $this->withToken($token)->postJson("/api/v1/threads/{$thread->id}/resume")->assertStatus(202)->assertJsonPath('data.auto.state', 'gating');
        $this->withToken($token)->postJson("/api/v1/threads/{$thread->id}/plan", ['decision' => 'approve'])
            ->assertStatus(422)->assertJsonPath('code', 'no_plan_waiting');
        $this->withToken($token)->postJson("/api/v1/threads/{$thread->id}/plan", ['decision' => 'maybe'])->assertStatus(422);
    }

    public function test_another_automatic_run_can_start_in_an_existing_workspace(): void
    {
        $workspace = Workspace::factory()->create(['repo_profile_id' => $this->project->id, 'status' => WorkspaceStatus::Running]);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->bot->id, 'role' => WorkspaceRole::Owner]);

        $this->withToken($this->clientToken())->postJson("/api/v1/workspaces/{$workspace->id}/threads", ['prompt' => 'Second task', 'plan' => 'off'])
            ->assertStatus(202)->assertJsonPath('data.auto.state', 'queued')->assertJsonPath('data.auto.plan', 'off');
    }
}
