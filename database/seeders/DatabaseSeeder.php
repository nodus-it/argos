<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Inside an Argos live-demo container (ARGOS_DEMO=1, injected by the
        // deployer) seed the rich demo data so every view is populated; anywhere
        // else stay production-safe and create only the admin user.
        if (config('argos.demo.enabled')) {
            $this->call(FullDemoSeeder::class);

            return;
        }

        $this->call(AdminUserSeeder::class);
    }
}
