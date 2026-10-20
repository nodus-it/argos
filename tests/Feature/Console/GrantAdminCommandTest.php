<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_grants_and_revokes_the_admin_role(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'pair@example.com']);

        $this->artisan('argos:user:admin', ['email' => 'pair@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->artisan('argos:user:admin', ['email' => 'pair@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_refuses_to_revoke_the_last_admin(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'only@example.com']);

        $this->artisan('argos:user:admin', ['email' => 'only@example.com', '--revoke' => true])->assertFailed();
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_unknown_email_fails(): void
    {
        $this->artisan('argos:user:admin', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
