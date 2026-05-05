<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectedAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_relink_orphaned_repo_profiles_attaches_only_matching_platform(): void
    {
        $user = User::factory()->create();
        $bbAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'bitbucket',
        ]);

        $orphanBb = RepoProfile::factory()->create([
            'platform' => 'bitbucket',
            'auth_method' => 'oauth',
            'connected_account_id' => null,
            'url' => 'https://bitbucket.org/ws/repo',
        ]);
        $orphanGh = RepoProfile::factory()->create([
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => null,
        ]);

        $count = $bbAccount->relinkOrphanedRepoProfiles();

        $this->assertSame(1, $count);
        $this->assertSame($bbAccount->id, $orphanBb->fresh()->connected_account_id);
        $this->assertNull($orphanGh->fresh()->connected_account_id);
    }

    public function test_relink_skips_pat_profiles(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
        ]);

        $patProfile = RepoProfile::factory()->create([
            'platform' => 'github',
            'auth_method' => 'pat',
            'token' => 'ghp_xxx',
            'connected_account_id' => null,
        ]);

        $account->relinkOrphanedRepoProfiles();

        $this->assertNull($patProfile->fresh()->connected_account_id);
    }

    public function test_relink_skips_already_linked_profiles(): void
    {
        $user = User::factory()->create();
        $other = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'gitlab',
        ]);
        $linkedProfile = RepoProfile::factory()->create([
            'platform' => 'gitlab',
            'auth_method' => 'oauth',
            'connected_account_id' => $other->id,
            'url' => 'https://gitlab.com/foo/bar',
        ]);

        // Reattach: own account is the same record (idempotent reconnect path)
        $count = $other->relinkOrphanedRepoProfiles();

        $this->assertSame(0, $count);
        $this->assertSame($other->id, $linkedProfile->fresh()->connected_account_id);
    }

    public function test_relink_returns_zero_on_first_connect(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
        ]);

        $this->assertSame(0, $account->relinkOrphanedRepoProfiles());
    }
}
