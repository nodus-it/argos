<?php

declare(strict_types=1);

namespace App\Testing;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Browser-E2E helper: seed a ConnectedAccount (OAuth) for the admin user, so an
 * OAuth-auth run can be driven without the real browser redirect flow (which is
 * not automatable offline). `expires_at` is left null so TokenRefresher never
 * tries to refresh.
 *
 * Registered ONLY by E2eFakeServiceProvider (env-gated, never in production),
 * so it is invisible in normal/production CLIs.
 */
class E2eConnectAccountCommand extends Command
{
    protected $signature = 'argos:e2e:connect-account
        {--provider=github : Platform key (github|gitlab|bitbucket)}
        {--instance= : Instance URL for self-hosted GitLab (optional)}';

    protected $description = 'E2E test mode: seed an OAuth ConnectedAccount for the admin user.';

    public function handle(): int
    {
        $user = User::query()->firstWhere('email', 'admin@argos.local') ?? User::query()->first();

        if ($user === null) {
            $this->error('No user found — run the database seeder first.');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');
        $instance = (string) ($this->option('instance') ?? '');

        $account = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => '10000001',
            'token' => 'e2e-fake-oauth-token',
            'refresh_token' => null,
            'expires_at' => null,
            'name' => 'E2E '.ucfirst($provider),
            'nickname' => 'e2e',
            'avatar' => '',
            'instance_url' => $instance,
        ]);

        $this->info("Connected account #{$account->id} ({$provider}) seeded for {$user->email}.");

        return self::SUCCESS;
    }
}
