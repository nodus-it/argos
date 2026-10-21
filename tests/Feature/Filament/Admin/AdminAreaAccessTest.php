<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Models\User;
use App\Models\Workspace;
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
        Workspace::factory()->create();
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

    public function test_workspace_list_and_agents_stay_open_to_everyone(): void
    {
        Workspace::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('filament.admin.resources.workspaces.index'))->assertOk();
        $this->get(route('filament.admin.resources.agent-credentials.index'))->assertOk();
        // Creating a workspace is reserved to Argos admins.
        $this->get(route('filament.admin.resources.workspaces.create'))->assertForbidden();
    }

    public function test_only_admins_are_sent_to_onboarding_before_the_first_workspace(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('filament.admin.pages.dashboard'))->assertOk();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.pages.onboarding'));
    }
}
