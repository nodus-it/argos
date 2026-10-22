<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_installation_gets_its_first_administrator(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(User::query()->where('email', 'admin@argos.local')->sole()->isAdmin());
    }

    public function test_a_removed_or_demoted_bootstrap_admin_stays_that_way(): void
    {
        $this->seed(AdminUserSeeder::class);
        User::factory()->admin()->create(['email' => 'ops@example.com']);
        User::query()->where('email', 'admin@argos.local')->delete();

        // Every container boot runs the seeder again.
        $this->seed(AdminUserSeeder::class);

        $this->assertFalse(User::query()->where('email', 'admin@argos.local')->exists());
    }
}
