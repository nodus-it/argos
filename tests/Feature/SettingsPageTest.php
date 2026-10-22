<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\App\Clusters\System\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test(Settings::class)
            ->assertSuccessful()
            ->assertSee(__('system.overview'));
    }

    public function test_settings_page_offers_the_application_log_download(): void
    {
        Livewire::test(Settings::class)
            ->assertSuccessful()
            ->assertSee(__('settings.blade.logs_download'))
            ->assertSee(route('system.log.download'), escape: false);
    }

    public function test_only_operators_reach_the_log_and_horizon(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('system.log.download'))->assertForbidden();
        $this->actingAs($member)->get('/horizon/api/stats')->assertForbidden();
        $this->assertFalse(Gate::forUser($member)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('viewHorizon'));
    }
}
