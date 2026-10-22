<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The first administrator of a fresh installation. Runs on every container
 * boot, so it only acts on an empty user table: an operator who renamed,
 * demoted or deleted this account keeps it that way (a lost admin role comes
 * back with `argos:user:admin`).
 */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }
        $password = (string) config('argos.admin_password');
        if ($password === '') {
            throw new RuntimeException('ADMIN_PASSWORD is not set: Argos creates no administrator with a known password.');
        }

        $admin = User::query()->create([
            'email' => 'admin@argos.local',
            'name' => 'Argos Admin',
            'password' => Hash::make($password),
        ]);

        // is_admin is not mass-assignable; the seeded account is the admin.
        $admin->forceFill(['is_admin' => true])->save();
    }
}
