<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\DemoComposeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DemoComposeGuardTest extends TestCase
{
    public function test_the_bundled_laravel_contract_passes_and_is_hardened(): void
    {
        $yaml = (string) file_get_contents(resource_path('stubs/demo/laravel/demo.compose.yml'));

        $compose = Yaml::parse((new DemoComposeGuard)->harden($yaml));

        foreach ($compose['services'] as $service) {
            $this->assertSame(['no-new-privileges:true'], $service['security_opt']);
            $this->assertSame(512, $service['pids_limit']);
        }
    }

    public function test_named_volumes_and_private_networks_pass(): void
    {
        $yaml = <<<'YAML'
            services:
              app:
                image: example/app:1
                volumes: [cache:/cache, {type: volume, source: data, target: /data}]
                networks: {internal: {aliases: [web]}}
            volumes: {cache: {}, data: ~}
            networks: {internal: {internal: true}}
            YAML;

        $this->assertStringContainsString('no-new-privileges', (new DemoComposeGuard)->harden($yaml));
    }

    public function test_the_implicit_default_network_needs_no_declaration(): void
    {
        $yaml = "services:\n  app:\n    image: x\n    networks: [default, backend]\nnetworks:\n  backend: {}\n";

        $this->assertStringContainsString('no-new-privileges', (new DemoComposeGuard)->harden($yaml));
    }

    /** @return array<string, array{string}> */
    public static function dangerous(): array
    {
        return [
            'privileged' => ["services:\n  app:\n    image: x\n    privileged: true\n"],
            'host path' => ["services:\n  app:\n    image: x\n    volumes: ['/:/host']\n"],
            'docker socket' => ["services:\n  app:\n    image: x\n    volumes: ['/var/run/docker.sock:/var/run/docker.sock']\n"],
            'bind mount' => ["services:\n  app:\n    image: x\n    volumes: [{type: bind, source: /etc, target: /etc}]\n"],
            'host network' => ["services:\n  app:\n    image: x\n    network_mode: host\n"],
            'capabilities' => ["services:\n  app:\n    image: x\n    cap_add: [SYS_ADMIN]\n"],
            'published port' => ["services:\n  app:\n    image: x\n    ports: ['80:80']\n"],
            'env file' => ["services:\n  app:\n    image: x\n    env_file: /data/config/.env\n"],
            'build context' => ["services:\n  app:\n    build: /\n"],
            'external network' => ["services:\n  app:\n    image: x\n    networks: [default]\nnetworks:\n  default: {external: true, name: argos_default}\n"],
            'undeclared network' => ["services:\n  app:\n    image: x\n    networks: [argos_default]\n"],
            'volume driver opts' => ["services:\n  app:\n    image: x\n    volumes: ['v:/v']\nvolumes:\n  v: {driver_opts: {type: none, o: bind, device: /}}\n"],
            'pid host' => ["services:\n  app:\n    image: x\n    pid: host\n"],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_dangerous_compose_is_refused(string $yaml): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Preview contract refused');

        (new DemoComposeGuard)->harden($yaml);
    }
}
