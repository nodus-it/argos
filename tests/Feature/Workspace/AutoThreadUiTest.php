<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\AutoThreadState;
use App\Enums\GitProvider;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Livewire\Workspace\ThreadChat;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AutoThreadUiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ThreadStream::class]);
        Notification::fake();
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $profile = RepoProfile::factory()->create(['url' => 'https://github.com/acme/widget', 'platform' => GitProvider::GitHub]);
        $this->workspace = Workspace::factory()->create(['repo_profile_id' => $profile->id, 'status' => WorkspaceStatus::Running]);
        $this->owner = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat']]);
        $git = Mockery::mock(GitServiceContract::class);
        $git->shouldReceive('createPullRequest')->andReturn(['number' => 3, 'html_url' => 'https://x/3', 'state' => 'open']);
        $this->mock(GitServiceFactory::class)->shouldReceive('fromRepoProfile')->andReturn($git);
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        parent::tearDown();
    }

    private function chat()
    {
        return Livewire::actingAs($this->owner)->test(ThreadChat::class, ['workspaceId' => $this->workspace->id]);
    }

    public function test_an_auto_thread_needs_a_task(): void
    {
        $this->chat()->set('newAuto', true)->set('newPrompt', '')->call('createThread')->assertHasErrors('newPrompt');
        $this->assertSame(0, Thread::query()->count());
    }

    public function test_the_form_starts_an_auto_thread_that_runs_to_the_pull_request(): void
    {
        config(['queue.default' => 'sync']);

        $chat = $this->chat()
            ->assertSee(__('workspaces.auto.toggle'))
            ->set('newAuto', true)
            ->set('newPrompt', 'Add a priority to notes')
            ->set('newPlan', 'off')
            ->call('createThread')
            ->assertHasNoErrors();

        $thread = Thread::query()->sole();
        $this->assertSame(AutoThreadState::Done, $thread->auto_state);
        $this->assertSame('off', $thread->auto_config['plan']);
        $chat->assertSee(AutoThreadState::Done->label())->assertSee(__('workspaces.auto.automated'));
    }

    public function test_the_plan_card_approves_through_the_driver(): void
    {
        config(['queue.default' => 'sync']);
        $chat = $this->chat()->set('newAuto', true)->set('newPrompt', 'Refactor login')->set('newPlan', 'approve')->call('createThread');
        $thread = Thread::query()->sole();
        $this->assertSame(AutoThreadState::AwaitingPlanApproval, $thread->auto_state);
        $chat->assertSee(AutoThreadState::AwaitingPlanApproval->label());

        $plan = ThreadApproval::query()->where('thread_id', $thread->id)->sole();
        $chat->set('threadId', $thread->id)->call('resolveApproval', $plan->id, 'allow');

        $this->assertSame(AutoThreadState::Done, $thread->fresh()->auto_state);
    }

    public function test_writing_takes_over_and_resume_hands_it_back(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $this->chat()->set('newAuto', true)->set('newPrompt', 'Do it')->call('createThread');
        $thread = Thread::query()->sole();
        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $thread->forceFill(['auto_state' => AutoThreadState::Implementing])->save();

        $chat = $this->chat()->set('threadId', $thread->id)->assertSee(__('workspaces.auto.pause'));
        $chat->set('draft', 'Let me help')->call('send');
        $this->assertSame(AutoThreadState::Paused, $thread->fresh()->auto_state);
        $chat->assertSee(__('workspaces.auto.resume'))->assertSee(__('workspaces.auto.paused_by', ['name' => 'Ada']));

        $chat->call('resumeAuto');
        $this->assertTrue($thread->fresh()->auto_state->isRunning());
    }

    public function test_a_new_workspace_can_start_an_auto_thread(): void
    {
        Bus::fake([ProvisionWorkspaceJob::class]);
        Filament::setCurrentPanel('workspaces');
        $this->actingAs($this->owner);

        Livewire::test(CreateWorkspace::class)
            ->fillForm(['repo_profile_id' => $this->workspace->repo_profile_id, 'name' => 'Priorities', 'auto_start' => true, 'auto_prompt' => 'Add priorities', 'auto_plan' => 'approve'])
            ->call('create')
            ->assertHasNoFormErrors();

        $workspace = Workspace::query()->where('name', 'Priorities')->sole();
        $thread = Thread::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(AutoThreadState::Queued, $thread->auto_state);
        $this->assertSame('approve', $thread->auto_config['plan']);
        $this->assertSame('Add priorities', $thread->auto_config['prompt']);
    }
}
