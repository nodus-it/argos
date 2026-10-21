<?php

declare(strict_types=1);

use App\Models\AgentCredential;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\BasicDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds exactly the admin user and nothing else', function () {
    $this->seed(BasicDemoSeeder::class);

    expect(User::count())->toBe(1);
    expect(User::first()->email)->toBe('admin@argos.local');
});

it('leaves onboarding incomplete so the user lands on step 1', function () {
    $this->seed(BasicDemoSeeder::class);

    // RedirectToOnboarding fires while no workspace exists; no agent yet either.
    expect(Workspace::exists())->toBeFalse();
    expect(AgentCredential::exists())->toBeFalse();
});
