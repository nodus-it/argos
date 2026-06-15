<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('grants panel access to any authenticated user by default', function (): void {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('routes panel access through the access-argos-panel gate', function (): void {
    // Redefining the gate is the single override point: a stricter auth layer
    // flips access without touching the User model or the panel.
    Gate::define('access-argos-panel', fn (User $user): bool => false);

    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});
