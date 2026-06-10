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
        // Absolute http:// base so the probe starts from plain HTTP regardless
        // of the ambient APP_URL (a developer's local .env may pin an https
        // APP_URL). That makes the X-Forwarded-Proto the thing that flips the
        // request to https — i.e. an actual test of the trusted-proxy header.
        $response = $this->get('http://localhost/__trust-proxies-probe', [
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
        // Absolute http:// base (see the note above) — without it an https
        // APP_URL in the local .env makes the request secure before the
        // middleware even runs, and this assertion flaps.
        $response = $this->get('http://localhost/__trust-proxies-probe');

        $response->assertOk();
        $response->assertJsonPath('secure', false);
        $response->assertJsonPath('scheme', 'http');
    }
}
