<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Argos' own live demo runs on the bundled default contract — no repo-side
 * .argos/demo.* anymore. Two guarantees replace it: the default contract boots
 * any Laravel repo without a key:generate step (the deployer injects APP_KEY),
 * and the seeder fills the full demo profile when the container is flagged a
 * live demo (ARGOS_DEMO=1 → config argos.demo.enabled). These assertions catch
 * a regression in CI instead of as a blank or broken live demo in production.
 */
class ArgosDemoSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_contract_boots_without_key_generate(): void
    {
        $settings = Yaml::parseFile(resource_path('stubs/demo/laravel/demo.yml'));
        $commands = implode("\n", $settings['commands']);

        // The deployer injects a throwaway APP_KEY env var, so the contract must
        // NOT run key:generate (which fails for repos — like Argos — that ship no
        // APP_KEY= line in .env.example for it to fill).
        $this->assertStringNotContainsString('key:generate', $commands);
        // Seeding still happens via the repo's own DatabaseSeeder.
        $this->assertStringContainsString('migrate --force --seed', $commands);
    }

    public function test_seeder_fills_full_demo_profile_in_demo_mode(): void
    {
        config()->set('argos.demo.enabled', true);

        $this->seed(DatabaseSeeder::class);

        // FullDemoSeeder ran (admin-only DatabaseSeeder creates no tasks).
        $this->assertGreaterThan(0, Task::query()->count());
        $this->assertDatabaseHas('users', ['email' => 'admin@argos.local']);
    }

    public function test_seeder_stays_admin_only_outside_demo_mode(): void
    {
        config()->set('argos.demo.enabled', false);

        $this->seed(DatabaseSeeder::class);

        // Production-safe default: just the admin user, no demo data.
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertDatabaseHas('users', ['email' => 'admin@argos.local']);
    }
}
