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
            ->assertSee('Einstellungen');
    }

    public function test_settings_shows_token_missing_state(): void
    {
        config(['argos.claude_token' => null]);

        Livewire::test(Settings::class)
            ->assertSee('nicht gesetzt')
            ->assertSee('claude setup-token');
    }

    public function test_settings_shows_token_set_state(): void
    {
        config(['argos.claude_token' => 'sk-ant-test-token']);

        Livewire::test(Settings::class)
            ->assertSee('gesetzt');
    }

    public function test_settings_shows_db_connection(): void
    {
        Livewire::test(Settings::class)
            ->assertSee(config('database.default'));
    }

    public function test_settings_shows_worker_image(): void
    {
        config(['argos.worker_image' => 'argos-worker:local']);

        Livewire::test(Settings::class)
            ->assertSee('argos-worker:local');
    }
}
