<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The app sits at the root; `/w/…` links from before keep working. */
class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_is_my_work(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/')->assertOk()->assertSee(__('workspaces.my_work.title'));
    }

    public function test_workspaces_and_tasks_live_at_their_names(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $workspace = Workspace::factory()->create(['name' => 'Argos Playground', 'slug' => 'argos-playground']);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
        $task = Task::factory()->ownedBy($user)->create(['workspace_id' => $workspace->id, 'slug' => 'fix-login']);

        $this->assertSame(url('/argos-playground/fix-login'), $task->url());
        $this->assertSame(url('/argos-playground'), $workspace->url());
        $this->actingAs($user)->get('/argos-playground/fix-login')->assertOk();
        $this->actingAs($user)->get('/argos-playground/settings')->assertForbidden();
    }

    public function test_old_links_are_redirected(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['slug' => 'widget']);
        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'slug' => 'fix-login']);
        $this->actingAs($user);

        $this->get('/w')->assertRedirect(url('/'));
        $this->get('/w/workspaces')->assertRedirect(url('/workspaces'));
        $this->get('/w/workspaces/create')->assertRedirect(url('/new'));
        $this->get("/w/workspaces/{$workspace->id}?task={$task->id}&review=1")->assertRedirect(url('/widget/fix-login').'?review=1');
        $this->get("/w/workspaces/{$workspace->id}/settings")->assertRedirect(url('/widget/settings'));
        $this->get('/w/workspaces/nope')->assertNotFound();
    }

    public function test_the_app_paths_are_never_a_workspace(): void
    {
        foreach (['docs', 'account', 'system', 'api', 'livewire-1a2b'] as $name) {
            $this->assertTrue(Workspace::isReservedSlug($name), $name);
        }
        $this->assertSame('docs-2', Workspace::generateSlug('Docs'));
        $this->assertMatchesRegularExpression('#^'.Workspace::routePattern().'/api$#', 'docs-2/api');
        $this->assertDoesNotMatchRegularExpression('#^(?:'.Workspace::routePattern().')/api$#', 'docs/api');

        // Every top-level path the app routes is reserved.
        $first = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => explode('/', $route->uri())[0])
            ->filter(fn (string $segment): bool => preg_match('/^[a-z0-9][a-z0-9-]*$/', $segment) === 1)
            ->unique();
        foreach ($first as $segment) {
            $this->assertTrue(Workspace::isReservedSlug($segment), "'{$segment}' is routed but not in argos.reserved_slugs");
        }
    }
}
