<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Enums\AgentName;
use App\Filament\Admin\Widgets\WorkerUpdatesWidget;
use App\Models\AgentVersion;
use App\Models\User;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkerUpdatesWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_widget_hidden_when_no_updates(): void
    {
        $this->assertFalse(WorkerUpdatesWidget::canView());
    }

    public function test_widget_visible_when_stack_has_update(): void
    {
        WorkerStack::factory()->create(['has_update' => true]);

        $this->assertTrue(WorkerUpdatesWidget::canView());
    }

    public function test_widget_visible_when_agent_has_update(): void
    {
        AgentVersion::factory()->withUpdate()->create([
            'agent_name' => AgentName::ClaudeCode,
        ]);

        $this->assertTrue(WorkerUpdatesWidget::canView());
    }

    public function test_widget_renders(): void
    {
        WorkerStack::factory()->create(['has_update' => true]);
        AgentVersion::factory()->withUpdate()->create();

        Livewire::test(WorkerUpdatesWidget::class)
            ->assertSuccessful();
    }
}
