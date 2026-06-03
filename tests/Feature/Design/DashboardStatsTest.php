<?php

declare(strict_types=1);

use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the redesigned control-room stat cards', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(StatsOverviewWidget::class)
        ->assertOk()
        ->assertSee('class="stats"', false)
        ->assertSee('class="num"', false);
});
