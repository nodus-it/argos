<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_slugify_is_dns_and_docker_safe(): void
    {
        $this->assertSame('ueber-grosse-aenderung', Workspace::slugify('Über große Änderung!'));
        $this->assertSame('a-b', Workspace::slugify('--A__b--'));
        $this->assertLessThanOrEqual(Workspace::SLUG_MAX - 6, strlen(Workspace::slugify(str_repeat('abc ', 40))));
        $this->assertStringEndsNotWith('-', Workspace::slugify(str_repeat('ab-', 40)));
    }

    public function test_generated_slugs_avoid_workspace_collisions(): void
    {
        Workspace::factory()->create(['slug' => 'login']);
        $this->assertSame('login-2', Workspace::generateSlug('Login'));

        $trashed = Workspace::factory()->create(['slug' => 'billing']);
        $trashed->delete();
        $this->assertSame('billing-2', Workspace::generateSlug('Billing'));

        $this->assertSame('workspace', Workspace::generateSlug('!!!'));
    }

    public function test_derived_resource_names(): void
    {
        $workspace = Workspace::factory()->make(['slug' => 'login']);

        $this->assertSame('ws_login', $workspace->volumeName());
        $this->assertSame('ws_login_agents', $workspace->agentsVolumeName());
        $this->assertSame('argos-ws-login', $workspace->containerName());
    }

    public function test_task_preview_slugs_combine_workspace_and_task_and_stay_dns_labels(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'shop']);
        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'slug' => 'checkout']);

        $this->assertSame('demo-shop-checkout', $task->demoSlug());
        $this->assertSame('ws_shop', $task->workspaceVolumeName());
        $this->assertTrue($task->demoWorkspace()->is($workspace));

        $long = Task::demoSlugFor(str_repeat('a', 40), str_repeat('b', 30).'-c');
        $this->assertLessThanOrEqual(63, strlen($long));
        $this->assertStringEndsNotWith('-', Task::demoSlugFor(str_repeat('a', 40), str_repeat('b', 16).'-cc'));
    }

    public function test_daemon_token_is_encrypted_and_hidden(): void
    {
        $workspace = Workspace::factory()->create(['daemon_token' => 'plain-secret']);

        $raw = \DB::table('workspaces')->where('id', $workspace->id)->value('daemon_token');
        $this->assertNotSame('plain-secret', $raw);
        $this->assertSame('plain-secret', $workspace->fresh()->daemon_token);
        $this->assertArrayNotHasKey('daemon_token', $workspace->toArray());
    }
}
