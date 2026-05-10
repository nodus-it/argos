<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectToOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_dashboard_redirects_to_onboarding_when_no_profile(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
    }

    public function test_tasks_index_redirects_to_onboarding_when_no_profile(): void
    {
        $response = $this->get(route('filament.admin.resources.tasks.index'));

        $response->assertRedirect(route('filament.admin.pages.onboarding'));
    }

    public function test_no_redirect_when_profile_exists(): void
    {
        RepoProfile::factory()->create();

        $response = $this->get(route('filament.admin.resources.tasks.index'));

        $response->assertOk();
    }

    public function test_onboarding_page_itself_is_not_redirected(): void
    {
        $response = $this->get(route('filament.admin.pages.onboarding'));

        $response->assertOk();
    }

    public function test_repo_profile_create_page_is_not_redirected(): void
    {
        $response = $this->get(route('filament.admin.resources.repo-profiles.create'));

        $response->assertOk();
    }

    public function test_agent_credential_create_page_is_not_redirected(): void
    {
        // Onboarding's Codex setup links to this route — the middleware must
        // let it through even though no RepoProfile exists yet.
        $response = $this->get(route('filament.admin.resources.agent-credentials.create'));

        $response->assertOk();
    }

    public function test_agent_credential_list_page_is_not_redirected(): void
    {
        $response = $this->get(route('filament.admin.resources.agent-credentials.index'));

        $response->assertOk();
    }
}
