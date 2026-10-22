<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectToOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_dashboard_redirects_to_onboarding_when_no_workspace(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
    }

    public function test_workspaces_index_redirects_to_onboarding_when_no_workspace(): void
    {
        $response = $this->get(route('filament.admin.resources.workspaces.index'));

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
    }

    public function test_no_redirect_when_workspace_exists(): void
    {
        Workspace::factory()->create();

        $response = $this->get(route('filament.admin.resources.workspaces.index'));

        $response->assertOk();
    }

    public function test_onboarding_page_itself_is_not_redirected(): void
    {
        $response = $this->get(route('filament.admin.pages.onboarding'));

        $response->assertOk();
    }

    public function test_workspace_create_page_is_not_redirected(): void
    {
        $response = $this->get(route('filament.admin.resources.workspaces.create'));

        $response->assertOk();
    }

    public function test_agent_credential_create_page_is_not_redirected(): void
    {
        // Shared agents are managed here — the middleware must let it
        // through even though no workspace exists yet.
        $response = $this->get(route('filament.admin.resources.agent-credentials.create'));

        $response->assertOk();
    }

    public function test_agent_credential_list_page_is_not_redirected(): void
    {
        $response = $this->get(route('filament.admin.resources.agent-credentials.index'));

        $response->assertOk();
    }

    public function test_the_profile_with_your_own_agents_is_reachable_before_the_first_workspace(): void
    {
        $this->get(route('filament.workspaces.auth.profile'))->assertOk();
        $this->get(route('filament.admin.auth.profile'))->assertOk();
    }
}
