<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Testing\E2eConnectAccountCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(Kernel::class)->registerCommand(new E2eConnectAccountCommand);
});

it('seeds a self-hosted GitLab OAuth account for the admin user', function (): void {
    User::factory()->create(['email' => 'admin@argos.local']);

    $this->artisan('argos:e2e:connect-account', [
        '--provider' => 'gitlab',
        '--instance' => 'https://gl.example.test',
    ])->assertSuccessful();

    expect(
        ConnectedAccount::query()
            ->where('provider', 'gitlab')
            ->where('instance_url', 'https://gl.example.test')
            ->whereNull('expires_at')
            ->exists()
    )->toBeTrue();
});

it('defaults to a github account with no instance url', function (): void {
    User::factory()->create(['email' => 'admin@argos.local']);

    $this->artisan('argos:e2e:connect-account')->assertSuccessful();

    $account = ConnectedAccount::query()->firstWhere('provider', 'github');
    expect($account)->not->toBeNull()
        ->and($account->instance_url)->toBe('')
        ->and($account->expires_at)->toBeNull();
});
