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
        User::firstOrCreate(
            ['email' => 'admin@argos.local'],
            [
                'name' => 'Argos Admin',
                'password' => Hash::make(env('ADMIN_PASSWORD', '12345')),
            ],
        );
    }
}
