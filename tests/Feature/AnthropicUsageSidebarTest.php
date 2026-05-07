<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\AnthropicUsageSidebar;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class AnthropicUsageSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_mount_rendert_ohne_fehler(): void
    {
        Livewire::test(AnthropicUsageSidebar::class)
            ->assertSuccessful();
    }

    public function test_initialer_state_ist_kein_fehler_und_keine_daten(): void
    {
        $component = Livewire::test(AnthropicUsageSidebar::class);

        $component->assertSet('error', false);
        $component->assertSet('fiveHour', null);
        $component->assertSet('sevenDay', null);
    }

    public function test_format_interval_fuer_minuten(): void
    {
        $component = new AnthropicUsageSidebar;
        $method = new ReflectionMethod($component, 'formatInterval');

        // Exakte Sekunden vermeiden Off-by-one durch Millisekunden-Drift
        $target = Carbon::now()->addSeconds(30 * 60 + 30); // 30m30s → "30m"
        $result = $method->invoke($component, $target);

        $this->assertSame('30m', $result);
    }

    public function test_format_interval_fuer_stunden_und_minuten(): void
    {
        $component = new AnthropicUsageSidebar;
        $method = new ReflectionMethod($component, 'formatInterval');

        $target = Carbon::now()->addSeconds(2 * 3600 + 15 * 60 + 30); // 2h15m30s → "2h 15m"
        $result = $method->invoke($component, $target);

        $this->assertSame('2h 15m', $result);
    }

    public function test_format_interval_fuer_volle_stunden(): void
    {
        $component = new AnthropicUsageSidebar;
        $method = new ReflectionMethod($component, 'formatInterval');

        $target = Carbon::now()->addSeconds(3 * 3600 + 30); // 3h0m30s → "3h"
        $result = $method->invoke($component, $target);

        $this->assertSame('3h', $result);
    }

    public function test_format_interval_fuer_tage_und_stunden(): void
    {
        $component = new AnthropicUsageSidebar;
        $method = new ReflectionMethod($component, 'formatInterval');

        $target = Carbon::now()->addSeconds(86400 + 4 * 3600 + 30); // 1d4h0m30s → "1d 4h"
        $result = $method->invoke($component, $target);

        $this->assertSame('1d 4h', $result);
    }

    public function test_format_interval_fuer_vergangenes_datum_gibt_jetzt_zurueck(): void
    {
        $component = new AnthropicUsageSidebar;
        $method = new ReflectionMethod($component, 'formatInterval');

        $target = Carbon::now()->subMinutes(10);
        $result = $method->invoke($component, $target);

        $this->assertSame('jetzt', $result);
    }
}
