<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\Profile;
use App\Filament\Admin\Resources\AgentCredentialResource;
use App\Models\AgentCredential;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['locale' => 'en']);
        $this->actingAs($this->user);
    }

    public function test_profile_page_renders_successfully(): void
    {
        Livewire::test(Profile::class)
            ->assertSuccessful();
    }

    public function test_profile_page_uses_full_admin_layout(): void
    {
        // isSimple()=false ensures the page renders inside .fi-main (index layout)
        // so all Argos-scoped CSS applies — without this, the form would render
        // in the simple/login-style layout where no custom input borders show.
        $this->assertFalse(Profile::isSimple());
    }

    public function test_profile_form_prefills_current_user_data(): void
    {
        Livewire::test(Profile::class)
            ->assertFormSet([
                'name' => $this->user->name,
                'email' => $this->user->email,
                'locale' => 'en',
            ]);
    }

    public function test_profile_can_save_name_and_locale(): void
    {
        Livewire::test(Profile::class)
            ->fillForm([
                'name' => 'Updated Name',
                'email' => $this->user->email,
                'locale' => 'de',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, [
            'id' => $this->user->id,
            'name' => 'Updated Name',
            'locale' => 'de',
        ]);
    }

    public function test_profile_saves_the_auto_task_consent(): void
    {
        $this->assertFalse($this->user->refresh()->auto_task_consent);

        Livewire::test(Profile::class)
            ->assertFormSet(['auto_task_consent' => false])
            ->fillForm(['auto_task_consent' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->user->refresh()->auto_task_consent);
    }

    public function test_profile_lists_only_my_own_agents_with_links_to_manage_them(): void
    {
        $mine = AgentCredential::factory()->ownedBy($this->user)->create(['name' => 'My Max plan']);
        AgentCredential::factory()->ownedBy(User::factory()->create())->create(['name' => 'Someone else']);
        AgentCredential::factory()->create(['name' => 'Shared team agent']);

        Livewire::test(Profile::class)
            ->assertSee(__('common.profile.section_agents'))
            ->assertSee('My Max plan')
            ->assertSee($mine->status->label())
            ->assertSee(AgentCredentialResource::getUrl('edit', ['record' => $mine], panel: 'admin'), false)
            ->assertSee(AgentCredentialResource::getUrl('create', panel: 'admin'), false)
            ->assertDontSee('Someone else')
            ->assertDontSee('Shared team agent');
    }

    public function test_profile_without_own_agents_explains_how_to_add_one(): void
    {
        Livewire::test(Profile::class)->assertSee(__('common.profile.agents_empty'));
    }

    public function test_members_can_reach_their_own_agent_to_manage_it(): void
    {
        Workspace::factory()->create();
        $mine = AgentCredential::factory()->ownedBy($this->user)->create();

        $this->get(AgentCredentialResource::getUrl('edit', ['record' => $mine], panel: 'admin'))->assertOk();
    }

    public function test_profile_renders_inside_the_workspaces_panel(): void
    {
        Workspace::factory()->create();
        Filament::setCurrentPanel('workspaces');

        $this->get(Filament::getPanel('workspaces')->getProfileUrl())
            ->assertOk()
            ->assertSee('wf-rail', false)
            ->assertSee(__('common.profile.section_auto'));
    }
}
