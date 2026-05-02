<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatsOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_widget_renders_without_error_when_empty(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSuccessful();
    }

    public function test_tasks_gesamt_zeigt_korrekte_anzahl(): void
    {
        Task::factory()->count(3)->create();

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('3');
    }

    public function test_aktive_phasen_zaehlt_nur_running_runs(): void
    {
        PhaseRun::factory()->running()->count(2)->create();
        PhaseRun::factory()->count(1)->create(); // completed

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('2');
    }

    public function test_abgeschlossen_heute_zaehlt_nur_completed_runs_von_heute(): void
    {
        PhaseRun::factory()->create(['status' => 'completed', 'finished_at' => now()]);
        PhaseRun::factory()->create(['status' => 'completed', 'finished_at' => now()->subDay()]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('1');
    }

    public function test_aktive_phasen_zeigt_null_wenn_keine_aktiven(): void
    {
        PhaseRun::factory()->count(3)->create(); // all completed

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('0');
    }

    public function test_kosten_und_tokens_werden_aggregiert(): void
    {
        PhaseRun::factory()->create([
            'cost_usd' => 0.25,
            'input_tokens' => 1500,
            'output_tokens' => 500,
            'finished_at' => now(),
        ]);
        PhaseRun::factory()->create([
            'cost_usd' => 0.75,
            'input_tokens' => 2000,
            'output_tokens' => 1000,
            'finished_at' => now()->subDay(),
        ]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('$1.0000')   // total
            ->assertSee('$0.2500')   // today
            ->assertSee('5,000');    // total tokens
    }
}
