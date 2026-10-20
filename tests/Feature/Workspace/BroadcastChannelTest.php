<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'argos',
            'broadcasting.connections.reverb.secret' => 'secret',
            'broadcasting.connections.reverb.app_id' => 'argos',
        ]);
        // Channels were registered on the boot-time (null) broadcaster.
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    private function auth(User $user, string $channel)
    {
        return $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_members_join_the_workspace_presence_channel(): void
    {
        $workspace = Workspace::factory()->create();
        $member = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Viewer]);

        $response = $this->auth($member, 'presence-workspace.'.$workspace->id)->assertOk();
        $this->assertStringContainsString('"name":"Ada"', (string) json_decode($response->getContent(), true)['channel_data']);

        $this->auth(User::factory()->create(), 'presence-workspace.'.$workspace->id)->assertForbidden();
        $this->auth(User::factory()->admin()->create(), 'presence-workspace.'.$workspace->id)->assertOk();
    }

    public function test_user_channels_are_private_to_their_user(): void
    {
        $user = User::factory()->create();

        $this->auth($user, 'private-user.'.$user->id)->assertOk();
        $this->auth(User::factory()->create(), 'private-user.'.$user->id)->assertForbidden();
    }
}
