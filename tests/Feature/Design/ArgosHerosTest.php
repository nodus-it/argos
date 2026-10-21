<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

// ── Projects list hero ───────────────────────────────────────────────────────

it('list repo profiles page renders the ph-hero with folder icon', function (): void {
    Livewire::test(ListRepoProfiles::class)
        ->assertSuccessful()
        ->assertSee('ph-hero', false)
        ->assertSee('ph-ic', false)
        ->assertDontSee('fi-header-heading');
});

it('projects list hero has a new-project link', function (): void {
    Livewire::test(ListRepoProfiles::class)
        ->assertSuccessful()
        ->assertSee(__('widgets.hero.new_project'));
});

it('projects list hero counts projects and running workspaces', function (): void {
    $project = RepoProfile::factory()->create();
    Workspace::factory()->for($project, 'repoProfile')->create();
    Workspace::factory()->for($project, 'repoProfile')->stopped()->create();

    Livewire::test(ListRepoProfiles::class)
        ->assertSuccessful()
        ->assertSee(__('widgets.hero.chips.projects'))
        ->assertSee(__('widgets.hero.chips.running_workspaces'));
});
