<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\AutoTaskState;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Workspaces\Pages\MyWork;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Livewire\Workspace\RailWorkspaces;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceSummaryService;
use App\Support\Initials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceRailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('workspaces');
        Event::fake([TaskStream::class, UserWorkspaceSignal::class]);
    }

    private function join(Workspace $workspace, User $user, WorkspaceRole $role = WorkspaceRole::Member): void
    {
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);
    }

    public function test_abbreviations_take_two_or_three_letters_from_the_name(): void
    {
        $this->assertSame('AR', Initials::abbreviate('argos'));
        $this->assertSame('AWA', Initials::abbreviate('Argos Web App'));
        $this->assertSame('SA', Initials::abbreviate('shop-api'));
        $this->assertSame('MSB', Initials::abbreviate('my_shop backend v2'));
        $this->assertSame('ÜB', Initials::abbreviate('übersicht'));
        $this->assertSame('?', Initials::abbreviate('  '));
    }

    public function test_rail_lists_my_workspaces_with_abbreviation_and_tooltip(): void
    {
        $me = User::factory()->create();
        $shop = Workspace::factory()->create(['name' => 'Shop API']);
        $argos = Workspace::factory()->create(['name' => 'argos']);
        $foreign = Workspace::factory()->create(['name' => 'Hidden Place']);
        $this->join($shop, $me);
        $this->join($argos, $me, WorkspaceRole::Viewer);

        Livewire::actingAs($me)->test(RailWorkspaces::class)
            ->assertSeeHtml('title="Shop API"')
            ->assertSeeHtml('title="argos"')
            ->assertSeeInOrder(['AR', 'SA'])
            ->assertSee($shop->url(), false)
            ->assertDontSee('Hidden Place')
            ->assertDontSee($foreign->url(), false);

        // Argos admins see every workspace.
        Livewire::actingAs(User::factory()->admin()->create())->test(RailWorkspaces::class)
            ->assertSeeHtml('title="Hidden Place"');
    }

    public function test_badge_counts_tasks_waiting_for_this_user_only(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Shop API']);
        $this->join($workspace, $me);
        $this->join($workspace, $bob);
        $make = fn (User $owner, array $attributes = []): Task => Task::factory()->ownedBy($owner)->create(['workspace_id' => $workspace->id, ...$attributes]);

        TaskApproval::factory()->create(['task_id' => $make($me, ['session_status' => SessionStatus::Running])->id]);
        $make($me, ['session_status' => SessionStatus::WaitingInput]);
        $make($me, ['auto_state' => AutoTaskState::AwaitingPlanApproval]);
        $invited = $make($bob, ['session_status' => SessionStatus::WaitingInput]);
        TaskMember::query()->create(['task_id' => $invited->id, 'user_id' => $me->id, 'invited_by' => $bob->id]);
        // Not counted: Bob's own waiting task, my running / archived / finished ones.
        $make($bob, ['session_status' => SessionStatus::WaitingInput]);
        $make($me, ['session_status' => SessionStatus::Running]);
        $make($me, ['session_status' => SessionStatus::WaitingInput, 'archived_at' => now()]);
        $make($me, ['session_status' => SessionStatus::WaitingInput, 'status' => TaskStatus::Done]);

        $summary = app(WorkspaceSummaryService::class);
        $this->assertSame([$workspace->id => 4], $summary->waitingCountsFor($me));
        $this->assertSame([$workspace->id => 2], $summary->waitingCountsFor($bob));

        Livewire::actingAs($me)->test(RailWorkspaces::class)
            ->assertSeeHtml('<span class="wf-rail-count" aria-hidden="true">4</span>')
            ->assertSee(__('workspaces.rail.workspace_waiting', ['name' => 'Shop API', 'count' => 4]));
    }

    public function test_active_workspace_is_highlighted(): void
    {
        $me = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Shop API']);
        $this->join($workspace, $me);

        $html = $this->actingAs($me)->get(ViewWorkspace::getUrl(['record' => $workspace]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="wf-rail-btn wf-rail-abbr is-active"[^>]*aria-label="Shop API"[^>]*aria-current="page"/', (string) $html);
    }

    public function test_administration_only_for_argos_admins(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();
        // Past onboarding: a workspace exists.
        Workspace::factory()->create();
        $url = MyWork::getUrl(panel: 'workspaces');

        $this->actingAs($member)->get($url)->assertOk()
            ->assertDontSee('wf-rail-admin', false)
            ->assertSee(url('/profile'), false);
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('wf-rail-admin', false)
            ->assertSee(__('workspaces.panel.admin'));
    }

    public function test_home_button_points_to_my_work_and_the_list_stays_reachable(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->get(MyWork::getUrl(panel: 'workspaces'))
            ->assertSee('class="wf-rail-btn wf-rail-home is-active"', false)
            ->assertSee(route('filament.workspaces.resources.workspaces.index'), false);
    }
}
