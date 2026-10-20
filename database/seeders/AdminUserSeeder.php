<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@argos.local'],
            [
                'name' => 'Argos Admin',
                'password' => Hash::make((string) config('argos.admin_password')),
            ],
        );

        // is_admin is not mass-assignable; the seeded account is the admin.
        if (! $admin->isAdmin()) {
            $admin->forceFill(['is_admin' => true])->save();
        }
    }
}
