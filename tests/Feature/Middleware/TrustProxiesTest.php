<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__trust-proxies-probe', fn () => [
            'secure' => request()->isSecure(),
            'scheme' => request()->getScheme(),
            'host' => request()->getHost(),
            'asset_url' => asset('build/test.js'),
        ]);
    }

    public function test_x_forwarded_proto_https_is_honoured(): void
    {
        $response = $this->get('/__trust-proxies-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'argos.example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('secure', true);
        $response->assertJsonPath('scheme', 'https');
        $response->assertJsonPath('host', 'argos.example.com');
        $this->assertStringStartsWith('https://argos.example.com/', $response->json('asset_url'));
    }

    public function test_request_without_forwarded_proto_stays_http(): void
    {
        $response = $this->get('/__trust-proxies-probe');

        $response->assertOk();
        $response->assertJsonPath('secure', false);
        $response->assertJsonPath('scheme', 'http');
    }
}
