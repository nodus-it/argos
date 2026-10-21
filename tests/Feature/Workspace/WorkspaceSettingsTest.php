<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\IntegrationProvider;
use App\Enums\TaskProviderSyncStatus;
use App\Enums\TaskProviderKind;
use App\Enums\WorkspaceRole;
use App\Filament\Admin\RelationManagers\ApiTokensRelationManager;
use App\Filament\Admin\Resources\WorkspaceResource\RelationManagers\TaskProviderBindingsRelationManager;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\EditWorkspaceSettings;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Workspaces\Resources\WorkspaceResource\RelationManagers\MembersRelationManager;
use App\Models\ProviderCredential;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceMemberService;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('workspaces');
        Queue::fake();
    }

    private function workspaceFor(User $user, WorkspaceRole $role = WorkspaceRole::Admin, array $attributes = []): Workspace
    {
        $workspace = Workspace::factory()->create($attributes);
        app(WorkspaceMemberService::class)->addAdmin($workspace, $user);
        if ($role !== WorkspaceRole::Admin) {
            // A second admin so the user's own role can be lowered.
            app(WorkspaceMemberService::class)->addAdmin($workspace, User::factory()->create());
            app(WorkspaceMemberService::class)->changeRole($workspace, $user, $role);
        }

        return $workspace;
    }

    public function test_the_settings_url_helper_points_below_the_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->assertSame(
            route('filament.workspaces.resources.workspaces.settings', ['record' => $workspace]),
            WorkspaceResource::getUrl('settings', ['record' => $workspace]),
        );
        $this->assertStringEndsWith("/w/workspaces/{$workspace->id}/settings", WorkspaceResource::getUrl('settings', ['record' => $workspace]));
    }

    public function test_workspace_admins_and_argos_admins_open_the_settings(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);
        $url = WorkspaceResource::getUrl('settings', ['record' => $workspace]);

        $this->actingAs($admin)->get($url)->assertOk()->assertSee(__('workspaces.settings.tabs.members'));
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    }

    public function test_members_and_viewers_get_403_and_strangers_404(): void
    {
        $member = User::factory()->create();
        $workspace = $this->workspaceFor($member, WorkspaceRole::Member);
        $viewer = User::factory()->create();
        app(WorkspaceMemberService::class)->add($workspace, User::factory()->admin()->create(), $viewer, WorkspaceRole::Viewer);
        $url = WorkspaceResource::getUrl('settings', ['record' => $workspace]);

        $this->actingAs($member)->get($url)->assertForbidden();
        $this->actingAs($viewer)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_the_general_tab_saves_name_branch_and_pattern(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSchemaStateSet(['name' => $workspace->name, 'default_branch' => 'main'])
            ->fillForm(['name' => 'Shop', 'default_branch' => 'develop', 'branch_pattern' => 'feature/{ticket}-{title}'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $workspace->refresh();
        $this->assertSame('Shop', $workspace->name);
        $this->assertSame('develop', $workspace->default_branch);
        $this->assertSame('feature/{ticket}-{title}', $workspace->branch_pattern);
    }

    public function test_the_general_tab_rejects_an_invalid_branch_pattern(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['branch_pattern' => 'feature {title}!'])
            ->call('save')
            ->assertHasFormErrors(['branch_pattern']);

        $this->assertNull($workspace->fresh()->branch_pattern);
    }

    public function test_the_environment_tab_saves_preview_services_and_secrets(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin, attributes: ['live_demo_enabled' => false]);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm([
                'live_demo_enabled' => true,
                'worker_services' => ['mysql'],
                'worker_env' => [['name' => 'MEILI_KEY', 'value' => 's3cret']],
                'composer_registries' => [['host' => 'packages.example.com', 'username' => 'token', 'token' => 'abc']],
            ], 'environmentForm')
            ->call('saveEnvironment')
            ->assertHasNoFormErrors(form: 'environmentForm')
            ->assertNotified();

        $workspace->refresh();
        $this->assertTrue($workspace->live_demo_enabled);
        $this->assertSame(['mysql'], $workspace->worker_services);
        $this->assertSame([['name' => 'MEILI_KEY', 'value' => 's3cret']], $workspace->worker_env);
        $this->assertSame('packages.example.com', $workspace->composer_registries[0]['host']);
    }

    public function test_the_environment_tab_shows_the_setup_state_and_the_setup_script_hint(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSee(__('workspaces.settings.setup_status.none'))
            ->assertSee('.argos/setup.sh');
    }

    public function test_the_resources_tab_saves_limits_and_validates_them(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['max_sessions' => 0], 'resourcesForm')
            ->call('saveResources')
            ->assertHasFormErrors(['max_sessions'], 'resourcesForm')
            ->fillForm(['max_sessions' => 5, 'max_previews' => 2, 'cpu_limit' => 1.5, 'memory_limit_mb' => 4096, 'cleanup_after_days' => 30], 'resourcesForm')
            ->call('saveResources')
            ->assertHasNoFormErrors(form: 'resourcesForm');

        $workspace->refresh();
        $this->assertSame(5, $workspace->max_sessions);
        $this->assertSame(2, $workspace->max_previews);
        $this->assertSame(1.5, (float) $workspace->cpu_limit);
        $this->assertSame(4096, $workspace->memory_limit_mb);
        $this->assertSame(30, $workspace->cleanup_after_days);
    }

    public function test_the_automation_tab_saves_clean_settings_and_offers_only_members(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);
        $outsider = User::factory()->create(['name' => 'Outsider']);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertFormFieldExists('automation_user_id', 'automationForm', fn ($field): bool => array_key_exists($admin->id, $field->getOptions()) && ! array_key_exists($outsider->id, $field->getOptions()))
            ->fillForm(['automation_user_id' => $admin->id, 'auto_settings' => ['plan' => 'approve', 'ship' => null, 'max_fix_rounds' => 2, 'max_turns' => null, 'max_cost_usd' => null, 'max_minutes' => null]], 'automationForm')
            ->call('saveAutomation')
            ->assertHasNoFormErrors(form: 'automationForm');

        $workspace->refresh();
        $this->assertSame($admin->id, $workspace->automation_user_id);
        $this->assertSame(['plan' => 'approve', 'max_fix_rounds' => 2], $workspace->auto_settings);
    }

    public function test_saving_one_tab_leaves_the_others_alone(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin, attributes: ['name' => 'Before']);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->fillForm(['name' => 'Unsaved'])
            ->fillForm(['max_sessions' => 4], 'resourcesForm')
            ->call('saveResources');

        $workspace->refresh();
        $this->assertSame('Before', $workspace->name);
        $this->assertSame(4, $workspace->max_sessions);
    }

    public function test_the_page_embeds_the_integration_and_member_managers(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);

        Livewire::actingAs($admin)->test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSuccessful()
            ->assertSeeLivewire(TaskProviderBindingsRelationManager::class)
            ->assertSeeLivewire(ApiTokensRelationManager::class)
            ->assertSeeLivewire(MembersRelationManager::class);
    }

    public function test_a_workspace_admin_adds_a_binding_with_close_on_merge(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);
        $credential = ProviderCredential::factory()->create(['provider' => IntegrationProvider::GitHub, 'label' => 'CI PAT']);
        Saloon::fake(['api.github.com/user/repos*' => MockResponse::make([['full_name' => 'acme/widget']])]);

        Livewire::actingAs($admin)->test(TaskProviderBindingsRelationManager::class, ['ownerRecord' => $workspace, 'pageClass' => EditWorkspaceSettings::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'github',
                'mode' => 'poll',
                'credential_ref' => "pat:{$credential->id}",
                'external_project_ref' => 'acme/widget',
                'close_on_merge' => false,
            ])
            ->assertHasNoActionErrors();

        $binding = TaskProviderBinding::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertFalse($binding->close_on_merge);
    }

    public function test_a_workspace_admin_connects_an_own_ticket_system(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);

        Livewire::actingAs($admin)->test(TaskProviderBindingsRelationManager::class, ['ownerRecord' => $workspace, 'pageClass' => EditWorkspaceSettings::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'kind' => 'custom',
                'external_project_ref' => 'Support desk',
                'callback_url' => 'https://desk.example.com/argos',
            ])
            ->assertHasNoActionErrors();

        $binding = TaskProviderBinding::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(TaskProviderKind::Custom, $binding->kind);
        $this->assertSame('https://desk.example.com/argos', $binding->callback_url);
        $this->assertSame(48, strlen((string) $binding->webhook_secret));
        $this->assertSame(TaskProviderSyncStatus::Active, $binding->sync_status);
    }

    public function test_integration_managers_are_for_workspace_admins_only(): void
    {
        $member = User::factory()->create();
        $workspace = $this->workspaceFor($member, WorkspaceRole::Member);

        $this->actingAs($member);
        $this->assertFalse(TaskProviderBindingsRelationManager::canViewForRecord($workspace, EditWorkspaceSettings::class));
        $this->assertFalse(ApiTokensRelationManager::canViewForRecord($workspace, EditWorkspaceSettings::class));
        $this->assertFalse(MembersRelationManager::canViewForRecord($workspace, EditWorkspaceSettings::class));
    }

    public function test_admins_add_re_role_and_remove_members(): void
    {
        $admin = User::factory()->create();
        $pair = User::factory()->create(['name' => 'Pair']);
        $workspace = $this->workspaceFor($admin);
        $manager = fn () => Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $workspace, 'pageClass' => EditWorkspaceSettings::class]);

        $manager()
            ->callAction(TestAction::make('addMember')->table(), ['user_id' => $pair->id, 'role' => WorkspaceRole::Member->value])
            ->assertNotified(__('workspaces.actions.member_added', ['name' => 'Pair']));
        $this->assertSame(WorkspaceRole::Member, $workspace->roleOf($pair));

        $entry = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $pair->id)->sole();
        $manager()
            ->assertCanSeeTableRecords([$entry])
            ->callAction(TestAction::make('changeRole')->table($entry), ['role' => WorkspaceRole::Viewer->value])
            ->assertNotified();
        $this->assertSame(WorkspaceRole::Viewer, $workspace->roleOf($pair));

        $manager()->callAction(TestAction::make('removeMember')->table($entry))->assertNotified();
        $this->assertNull($workspace->roleOf($pair));
    }

    public function test_the_last_admin_cannot_be_demoted_or_removed(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);
        $entry = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $admin->id)->sole();
        $manager = fn () => Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $workspace, 'pageClass' => EditWorkspaceSettings::class]);

        $manager()
            ->callAction(TestAction::make('changeRole')->table($entry), ['role' => WorkspaceRole::Member->value])
            ->assertNotified(__('workspaces.errors.last_admin'));
        $manager()
            ->callAction(TestAction::make('removeMember')->table($entry))
            ->assertNotified(__('workspaces.errors.last_admin'));

        $this->assertSame(WorkspaceRole::Admin, $workspace->roleOf($admin));
    }

    public function test_member_actions_are_hidden_without_collaboration(): void
    {
        config(['argos.workspaces.collaboration' => false]);
        $admin = User::factory()->create();
        $workspace = $this->workspaceFor($admin);
        $entry = WorkspaceMember::query()->where('workspace_id', $workspace->id)->sole();

        Livewire::actingAs($admin)->test(MembersRelationManager::class, ['ownerRecord' => $workspace, 'pageClass' => EditWorkspaceSettings::class])
            ->assertCanSeeTableRecords([$entry])
            ->assertActionHidden(TestAction::make('addMember')->table())
            ->assertActionHidden(TestAction::make('changeRole')->table($entry))
            ->assertActionHidden(TestAction::make('removeMember')->table($entry));
    }

    public function test_only_argos_admins_open_the_create_page(): void
    {
        $admin = User::factory()->create();
        $this->workspaceFor($admin);

        $this->actingAs($admin)->get(WorkspaceResource::getUrl('create'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(WorkspaceResource::getUrl('create'))->assertOk();
    }

    public function test_argos_admins_create_a_workspace_and_land_in_it(): void
    {
        $creator = User::factory()->admin()->create();
        Saloon::fake(['api.github.com/repos/org/repo/branches*' => MockResponse::make([['name' => 'main']])]);

        $page = Livewire::actingAs($creator)->test(CreateWorkspace::class)
            ->fillForm([
                'platform' => 'github',
                'name' => 'Shop',
                'branch_pattern' => '{ticket}-{title}',
                'repo_url' => 'https://github.com/org/repo',
                'token' => 'pat-123',
                'default_branch' => 'main',
                'max_previews' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $workspace = Workspace::query()->where('name', 'Shop')->sole();
        $page->assertRedirect(WorkspaceResource::getUrl('view', ['record' => $workspace]));
        $this->assertSame('{ticket}-{title}', $workspace->branch_pattern);
        $this->assertSame(2, $workspace->max_previews);
        $this->assertSame(WorkspaceRole::Admin, $workspace->roleOf($creator));
    }

    public function test_the_list_links_the_settings_for_those_who_may_change_them(): void
    {
        $admin = User::factory()->create();
        $mine = $this->workspaceFor($admin);
        $member = User::factory()->create();
        $theirs = $this->workspaceFor($member, WorkspaceRole::Member);

        Livewire::actingAs($admin)->test(ListWorkspaces::class)
            ->assertActionVisible(TestAction::make('settings')->table($mine))
            ->assertActionHasUrl(TestAction::make('settings')->table($mine), WorkspaceResource::getUrl('settings', ['record' => $mine]));

        Livewire::actingAs($member)->test(ListWorkspaces::class)
            ->assertActionHidden(TestAction::make('settings')->table($theirs));
    }
}
