<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test(Settings::class)
            ->assertSuccessful()
            ->assertSee(__('settings.title'));
    }

    public function test_settings_page_offers_the_application_log_download(): void
    {
        Livewire::test(Settings::class)
            ->assertSuccessful()
            ->assertSee(__('settings.blade.logs_download'))
            ->assertSee(route('system.log.download'), escape: false);
    }
}
