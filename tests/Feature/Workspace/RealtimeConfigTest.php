<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_ships_runtime_realtime_config_only_with_reverb(): void
    {
        RepoProfile::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('filament.admin.pages.dashboard'))->assertOk()->assertDontSee('window.ArgosRealtime', false);

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'argos-key']);
        $this->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertSee('window.ArgosRealtime', false)
            ->assertSee('"key":"argos-key"', false)
            ->assertSee('broadcasting\/auth', false);
    }
}
