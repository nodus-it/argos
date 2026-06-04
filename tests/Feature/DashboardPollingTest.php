<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The warm-paper redesign silently dropped the dashboard auto-refresh: the
 * custom stats view lost its wire:poll attribute, and the task-list widget set
 * the class-level $pollingInterval, which table widgets ignore (polling is
 * driven by the table's own poll()). These assert the rendered markup actually
 * carries wire:poll so the regression cannot return unnoticed.
 */
class DashboardPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_stats_overview_widget_polls(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('wire:poll.5s', escape: false);
    }

    public function test_current_tasks_widget_polls(): void
    {
        Task::factory()->create();

        Livewire::test(CurrentTasksWidget::class)
            ->assertSee('wire:poll.5s', escape: false);
    }

    public function test_task_list_page_polls(): void
    {
        Task::factory()->create();

        Livewire::test(ListTasks::class)
            ->assertSee('wire:poll.5s', escape: false);
    }

    public function test_dashboard_page_renders_both_polling_widgets(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSeeLivewire(StatsOverviewWidget::class)
            ->assertSeeLivewire(CurrentTasksWidget::class);
    }
}
