<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Support\DemoUserBuilder;
use Illuminate\Database\Seeder;

/**
 * Basic profile: the minimum to log in and START onboarding.
 *
 * Seeds only the admin user — deliberately NO RepoProfile and NO agent
 * credential, so RedirectToOnboarding fires and the user lands on onboarding
 * step 1. Use for verifying the onboarding flow from scratch.
 *
 * Run via `composer dev:basic` (→ .tools/bin/dev-reset.sh basic) or
 * `php artisan db:seed --class=BasicDemoSeeder`.
 */
final class BasicDemoSeeder extends Seeder
{
    public function run(): void
    {
        (new DemoUserBuilder($this->command))->adminUser();

        $this->command?->info('Basic profile seeded: admin user only (onboarding starts from scratch).');
    }
}
