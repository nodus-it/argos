<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Support\WorkspaceHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceHostsTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function setups(): array
    {
        return [
            'subdomains of the app host' => ['https://argos.example.com', 'argos.example.com', true],
            'app is a subdomain of the base' => ['https://argos.example.com', 'example.com', true],
            'local ip with nip.io' => ['http://127.0.0.1:8080', '127.0.0.1.nip.io', false],
            'unrelated domains' => ['https://argos.example.com', 'preview.other.dev', false],
            'suffix trap' => ['https://example.com', 'notexample.com', false],
        ];
    }

    #[DataProvider('setups')]
    public function test_embeddable(string $appUrl, string $base, bool $expected): void
    {
        config(['app.url' => $appUrl, 'argos.preview.base_domain' => $base]);

        $this->assertSame($expected, WorkspaceHosts::embeddable());
    }
}
