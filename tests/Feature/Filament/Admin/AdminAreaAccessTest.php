<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAreaAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function adminRoutes(): array
    {
        return [
            'filament.admin.resources.worker-stacks.index',
            'filament.admin.resources.worker-image-builds.index',
            'filament.admin.resources.provider-o-auth-configs.index',
            'filament.admin.resources.api-clients.index',
            'filament.admin.resources.provider-credentials.index',
            'filament.admin.resources.users.index',
            'filament.admin.pages.settings',
            'filament.admin.pages.onboarding',
        ];
    }

    public function test_platform_administration_is_admin_only(): void
    {
        RepoProfile::factory()->create();
        $routes = array_filter($this->adminRoutes(), fn (string $name): bool => \Route::has($name));
        $this->assertNotEmpty($routes);

        $this->actingAs(User::factory()->create());
        foreach ($routes as $name) {
            $this->get(route($name))->assertForbidden();
        }

        $this->actingAs(User::factory()->admin()->create());
        foreach ($routes as $name) {
            $this->assertLessThan(400, $this->get(route($name))->getStatusCode(), $name);
        }
    }

    public function test_projects_and_agents_stay_open_to_everyone(): void
    {
        RepoProfile::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('filament.admin.resources.repo-profiles.index'))->assertOk();
        $this->get(route('filament.admin.resources.agent-credentials.index'))->assertOk();
    }

    public function test_only_admins_are_sent_to_onboarding_before_the_first_project(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('filament.admin.pages.dashboard'))->assertOk();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.pages.onboarding'));
    }
}
