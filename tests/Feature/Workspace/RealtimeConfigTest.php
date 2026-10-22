<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_ships_runtime_realtime_config_only_with_reverb(): void
    {
        Workspace::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('filament.workspaces.pages.my-work'))->assertOk()->assertDontSee('window.ArgosRealtime', false);

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'argos-key']);
        $this->get(route('filament.workspaces.pages.my-work'))
            ->assertOk()
            ->assertSee('window.ArgosRealtime', false)
            ->assertSee('"key":"argos-key"', false)
            ->assertSee('broadcasting\/auth', false);
    }
}
