<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

// ── Projects list hero ───────────────────────────────────────────────────────

it('admin workspaces list page renders the ph-hero with folder icon', function (): void {
    Livewire::test(ListWorkspaces::class)
        ->assertSuccessful()
        ->assertSee('ph-hero', false)
        ->assertSee('ph-ic', false)
        ->assertDontSee('fi-header-heading');
});

it('projects list hero has a new-project link', function (): void {
    Livewire::test(ListWorkspaces::class)
        ->assertSuccessful()
        ->assertSee(__('widgets.hero.new_project'));
});

it('projects list hero counts workspaces and running workspaces', function (): void {
    Workspace::factory()->count(2)->create();
    Workspace::factory()->stopped()->create();

    Livewire::test(ListWorkspaces::class)
        ->assertSuccessful()
        ->assertSee(__('widgets.hero.chips.projects'))
        ->assertSee(__('widgets.hero.chips.running_workspaces'));
});
