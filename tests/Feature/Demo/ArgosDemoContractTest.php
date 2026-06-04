<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use Database\Seeders\FullDemoSeeder;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Guards Argos' own committed live-demo contract (.argos/demo.*). The deployer
 * reads these from the repo at deploy time, so a typo, a half-written pair, or
 * a renamed seeder would only surface as a failed live demo in production —
 * these assertions catch it in CI instead.
 */
class ArgosDemoContractTest extends TestCase
{
    public function test_both_contract_files_exist(): void
    {
        // A half-written contract (only one file) makes the deployer throw
        // instead of falling back to the default — both must be present.
        $this->assertFileExists(base_path('.argos/demo.yml'));
        $this->assertFileExists(base_path('.argos/demo.compose.yml'));
    }

    public function test_settings_seed_the_full_demo_profile(): void
    {
        $settings = Yaml::parseFile(base_path('.argos/demo.yml'));

        $this->assertSame('app', $settings['entry']['service']);
        $this->assertSame(80, $settings['entry']['port']);
        $this->assertSame('/var/www/html', $settings['workspace_mount']);
        $this->assertSame('/', $settings['health']['path']);

        $commands = implode("\n", $settings['commands']);
        // The whole point of the repo contract: seed the rich demo data, not the
        // production-safe default DatabaseSeeder (admin user only).
        $this->assertStringContainsString('db:seed --class=FullDemoSeeder', $commands);
        $this->assertTrue(class_exists(FullDemoSeeder::class));
    }

    public function test_compose_reuses_builtin_runtime_via_placeholder(): void
    {
        $raw = (string) file_get_contents(base_path('.argos/demo.compose.yml'));

        // Keeping the placeholder lets the deployer inject the built-in runtime
        // image tag — no separate registry image to maintain.
        $this->assertStringContainsString('__ARGOS_DEMO_IMAGE__', $raw);

        $compose = Yaml::parse($raw);
        $this->assertArrayHasKey('app', $compose['services']);
        $this->assertSame('__ARGOS_DEMO_IMAGE__', $compose['services']['app']['image']);
    }
}
