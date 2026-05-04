<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestProvidersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_runs_clean_when_nothing_to_delete(): void
    {
        $this->artisan('test:providers', ['--reset' => true])
            ->expectsOutputToContain('Reset: 0 Profile, 0 ConnectedAccounts gelöscht.')
            ->assertExitCode(0);
    }

    public function test_reset_deletes_only_prefixed_profiles_and_their_accounts(): void
    {
        $user = User::factory()->create();

        $contractAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'token' => 'gho_xyz',
        ]);

        // Profile that should be deleted: name starts with [contract-test].
        RepoProfile::factory()->create([
            'name' => '[contract-test] github-pat',
            'platform' => 'github',
            'auth_method' => 'pat',
            'token' => 'pat',
            'connected_account_id' => null,
        ]);
        RepoProfile::factory()->create([
            'name' => '[contract-test] github-oauth',
            'platform' => 'github',
            'auth_method' => 'oauth',
            'token' => null,
            'connected_account_id' => $contractAccount->id,
        ]);

        // Profile that must not be touched (different name).
        $kept = RepoProfile::factory()->create([
            'name' => 'My Real Project',
            'platform' => 'github',
            'auth_method' => 'pat',
            'token' => 'pat',
        ]);

        $this->artisan('test:providers', ['--reset' => true])
            ->expectsOutputToContain('Reset: 2 Profile, 1 ConnectedAccounts gelöscht.')
            ->assertExitCode(0);

        $this->assertDatabaseHas(RepoProfile::class, ['id' => $kept->id]);
        $this->assertDatabaseMissing(ConnectedAccount::class, ['id' => $contractAccount->id]);
        $this->assertSame(0, RepoProfile::where('name', 'like', '[contract-test]%')->count());
    }

    public function test_unknown_user_email_returns_failure(): void
    {
        $this->artisan('test:providers', ['--user-email' => 'noone@example.invalid'])
            ->expectsOutputToContain('Kein User mit email=noone@example.invalid gefunden.')
            ->assertExitCode(1);
    }

    public function test_no_user_in_db_returns_failure(): void
    {
        // RefreshDatabase guarantees an empty users table here.
        $this->artisan('test:providers')
            ->expectsOutputToContain('Kein User in der DB.')
            ->assertExitCode(1);
    }
}
