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

    public function test_session_cookie_name_is_env_overridable_for_demos(): void
    {
        // Argos-deployed-as-its-own-demo sets a distinct SESSION_COOKIE
        // (.argos/demo.compose.yml) so the parent's domain-spanning
        // `argos_session` cookie can't shadow it. Default stays unchanged.
        $this->assertSame('argos_session', $this->freshConfig('http://localhost', 'session')['cookie']);

        Env::getRepository()->set('SESSION_COOKIE', 'argos_demo_session');
        try {
            $cfg = require dirname(__DIR__, 2).'/config/session.php';
        } finally {
            Env::getRepository()->clear('SESSION_COOKIE');
        }

        $this->assertSame('argos_demo_session', $cfg['cookie']);
    }

    public function test_concurrent_demos_get_non_overlapping_cookie_domains(): void
    {
        // Each demo runs under its own subdomain, so its cookie domain is scoped
        // to that subdomain. Two demos are SIBLINGS — neither domain is a parent
        // of the other — so a shared static SESSION_COOKIE name never collides
        // across demos (only the differently-named parent cookie spans them).
        $a = $this->freshConfig('https://demo-task-a.argos.example.com', 'session')['domain'];
        $b = $this->freshConfig('https://demo-task-b.argos.example.com', 'session')['domain'];

        $this->assertSame('.demo-task-a.argos.example.com', $a);
        $this->assertSame('.demo-task-b.argos.example.com', $b);
        // Sibling check: neither is a suffix-domain of the other.
        $this->assertStringNotContainsString($a, $b);
        $this->assertStringNotContainsString($b, $a);
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

    /**
     * The canonical docker-compose forwards the whole preview block as empty
     * `${VAR:-}` so config defaults apply. A set-but-empty env must therefore
     * behave like "unset" — otherwise default_image collapses to '' and the
     * demo image tag becomes `:hash`, which `docker build` rejects (and
     * ttl_hours/port/network silently go to 0/'').
     */
    public function test_empty_preview_env_falls_back_to_defaults(): void
    {
        $keys = [
            'ARGOS_PREVIEW_DEFAULT_IMAGE', 'ARGOS_PREVIEW_TTL_HOURS', 'ARGOS_PREVIEW_PORT',
            'ARGOS_PREVIEW_NETWORK', 'ARGOS_PREVIEW_AUTH', 'ARGOS_PREVIEW_AUTH_GATE_URL',
            'ARGOS_PREVIEW_BASIC_USER', 'ARGOS_PREVIEW_MAX_CONCURRENT',
            'ARGOS_PREVIEW_CPU_LIMIT', 'ARGOS_PREVIEW_MEM_LIMIT', 'ARGOS_PORT',
        ];
        foreach ($keys as $key) {
            Env::getRepository()->set($key, '');
        }

        try {
            $cfg = (require dirname(__DIR__, 2).'/config/argos.php')['preview'];
        } finally {
            foreach ($keys as $key) {
                Env::getRepository()->clear($key);
            }
        }

        $this->assertSame('argos-demo', $cfg['default_image']);
        $this->assertSame(24, $cfg['ttl_hours']);
        $this->assertSame(8080, $cfg['port']);
        $this->assertSame('argos_edge', $cfg['network']);
        $this->assertSame('none', $cfg['auth']);
        $this->assertSame('http://nginx:80/_argos/demo-gate', $cfg['auth_gate_url']);
        $this->assertSame('demo', $cfg['basic_user']);
        $this->assertSame(10, $cfg['max_concurrent']);
        $this->assertSame('1.0', $cfg['cpu_limit']);
        $this->assertSame('1g', $cfg['memory_limit']);
    }
}
