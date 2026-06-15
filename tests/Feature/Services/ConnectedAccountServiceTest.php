<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\OAuth\ConnectedAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists every connected account of the user when no provider is given', function (): void {
    $user = User::factory()->create();
    ConnectedAccount::factory()->for($user)->create(['provider' => 'github']);
    ConnectedAccount::factory()->for($user)->create(['provider' => 'linear']);
    ConnectedAccount::factory()->for(User::factory()->create())->create(['provider' => 'github']);

    $accounts = app(ConnectedAccountService::class)->selectableFor($user);

    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('provider')->sort()->values()->all())->toBe(['github', 'linear']);
});

it('narrows the listing to the given providers', function (): void {
    $user = User::factory()->create();
    ConnectedAccount::factory()->for($user)->create(['provider' => 'github']);
    ConnectedAccount::factory()->for($user)->create(['provider' => 'gitlab']);
    ConnectedAccount::factory()->for($user)->create(['provider' => 'linear']);

    $accounts = app(ConnectedAccountService::class)->selectableFor($user, ['github', 'gitlab']);

    expect($accounts->pluck('provider')->sort()->values()->all())->toBe(['github', 'gitlab']);
});
