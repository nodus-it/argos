<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * The closed stack derives host/scheme/cookie config from APP_URL so a single
 * APP_URL is the source of truth. These re-evaluate the config files fresh
 * under a controlled APP_URL (config() returns the already-booted values).
 */
class ConfigDerivationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function freshConfig(string $appUrl, string $file): array
    {
        Env::getRepository()->set('APP_URL', $appUrl);

        try {
            return require dirname(__DIR__, 2).'/config/'.$file.'.php';
        } finally {
            Env::getRepository()->clear('APP_URL');
        }
    }

    public function test_session_cookie_domain_gets_leading_dot_for_a_real_domain(): void
    {
        $cfg = $this->freshConfig('https://argos.example.com', 'session');

        // Leading dot so the session spans demo-<task>.argos.example.com.
        $this->assertSame('.argos.example.com', $cfg['domain']);
        $this->assertTrue($cfg['secure']);
    }

    public function test_session_cookie_domain_is_host_only_for_localhost(): void
    {
        $cfg = $this->freshConfig('http://localhost:8080', 'session');

        $this->assertNull($cfg['domain']);
        $this->assertFalse($cfg['secure']);
    }

    public function test_session_cookie_domain_is_host_only_for_nip_io(): void
    {
        $cfg = $this->freshConfig('http://127.0.0.1.nip.io:8080', 'session');

        $this->assertNull($cfg['domain']);
    }

    public function test_preview_base_domain_and_scheme_derive_from_app_url(): void
    {
        $cfg = $this->freshConfig('https://argos.example.com', 'argos');

        $this->assertSame('argos.example.com', $cfg['preview']['base_domain']);
        $this->assertSame('https', $cfg['preview']['scheme']);
    }

    public function test_preview_base_domain_falls_back_to_nip_io_locally(): void
    {
        $cfg = $this->freshConfig('http://localhost:8080', 'argos');

        // localhost has no usable wildcard DNS — nip.io gives one for free.
        $this->assertSame('127.0.0.1.nip.io', $cfg['preview']['base_domain']);
        $this->assertSame('http', $cfg['preview']['scheme']);
    }
}
