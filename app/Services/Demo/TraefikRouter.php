<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\DemoAccessMode;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Owns the Traefik file-provider route for a demo: writing the dynamic-config
 * route file, attaching the access-mode auth middleware (session forwardAuth /
 * shared basic-auth / none), recovering the upstream port from an existing
 * route, and building the public demo URL. Split out of DemoDeployer so routing
 * is one concern; the deployer orchestrates and asks the router for routes/URLs.
 */
class TraefikRouter
{
    /**
     * Write the Traefik file-provider route for this demo into the shared dir
     * and return the public URL. Traefik resolves the `{slug}` host alias on the
     * edge network to the entry container. The access mode attaches an auth
     * middleware (session forwardAuth / shared basic-auth) or none (public).
     */
    public function writeRoute(
        string $slug,
        int $port,
        DemoAccessMode $mode = DemoAccessMode::Public,
        ?string $basicPassword = null,
    ): string {
        $host = $slug.'.'.config('argos.preview.base_domain', '127.0.0.1.nip.io');

        $router = [
            'rule' => "Host(`{$host}`)",
            'entryPoints' => ['web'],
            'service' => $slug,
        ];

        $middlewares = $this->buildAuthMiddleware($slug, $mode, $basicPassword);
        if ($middlewares !== []) {
            $router['middlewares'] = array_keys($middlewares);
        }

        $http = [
            'routers' => [$slug => $router],
            'services' => [
                $slug => [
                    'loadBalancer' => [
                        'servers' => [
                            ['url' => "http://{$slug}:{$port}"],
                        ],
                    ],
                ],
            ],
        ];
        if ($middlewares !== []) {
            $http['middlewares'] = $middlewares;
        }

        $dir = $this->traefikDir();
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Traefik dynamic-config dir not writable: {$dir}");
        }

        // Write atomically (temp + rename). An in-place overwrite only emits a
        // MODIFY event, which Traefik's file watcher misses when the write comes
        // from another container over a shared volume — so a live access-mode
        // change would not take effect until the next rebuild. A rename emits a
        // CREATE/MOVED_TO event that the watcher reliably picks up, and Traefik
        // never reads a half-written file. The `.tmp` extension is ignored by
        // the file provider (it only loads .yml/.yaml/.toml/.json).
        $path = $this->routeFilePath($slug);
        $tmp = $path.'.tmp';
        file_put_contents($tmp, Yaml::dump(['http' => $http], 8, 2));
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write Traefik route: {$path}");
        }

        return $this->urlForSlug($slug);
    }

    /**
     * Remove a demo's route file (used on teardown). Safe to call when the file
     * does not exist.
     */
    public function removeRoute(string $slug): void
    {
        $route = $this->routeFilePath($slug);
        if (is_file($route)) {
            @unlink($route);
        }
    }

    /**
     * Recover the upstream port from an existing route file's service URL
     * (`http://{slug}:{port}`). Returns null when the file is missing or
     * unparseable.
     */
    public function existingRoutePort(string $slug): ?int
    {
        $file = $this->routeFilePath($slug);
        if (! is_file($file)) {
            return null;
        }

        try {
            $parsed = Yaml::parseFile($file);
        } catch (Throwable) {
            return null;
        }

        $url = $parsed['http']['services'][$slug]['loadBalancer']['servers'][0]['url'] ?? null;
        if (! is_string($url) || preg_match('/:(\d+)$/', $url, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** Public URL for a demo slug (scheme + slug.base_domain + external port). */
    public function urlForSlug(string $slug): string
    {
        return $this->url($slug.'.'.config('argos.preview.base_domain', '127.0.0.1.nip.io'));
    }

    /**
     * Build the Traefik middleware definitions for the resolved access mode,
     * keyed by middleware name (referenced from the router). Empty for public.
     * Fails closed: basic mode without any password throws rather than shipping
     * an unprotected demo.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildAuthMiddleware(string $slug, DemoAccessMode $mode, ?string $basicPassword): array
    {
        $name = $slug.'-auth';

        return match ($mode->resolve()) {
            DemoAccessMode::Session => [
                $name => [
                    'forwardAuth' => [
                        'address' => (string) config('argos.preview.auth_gate_url', 'http://nginx:80/_argos/demo-gate'),
                        'trustForwardHeader' => true,
                    ],
                ],
            ],
            DemoAccessMode::Basic => [
                $name => [
                    'basicAuth' => [
                        'users' => [$this->basicAuthUserLine($basicPassword)],
                    ],
                ],
            ],
            default => [],
        };
    }

    /**
     * Render the `user:bcrypt-hash` line Traefik basicAuth expects. The password
     * is the per-task one, falling back to the global config password.
     */
    private function basicAuthUserLine(?string $basicPassword): string
    {
        $password = $basicPassword ?: (string) config('argos.preview.basic_password', '');
        if ($password === '') {
            throw new RuntimeException(
                'Demo basic-auth selected but no password set (task password or ARGOS_PREVIEW_BASIC_PASSWORD).'
            );
        }

        $user = (string) config('argos.preview.basic_user', 'demo');

        return $user.':'.password_hash($password, PASSWORD_BCRYPT);
    }

    /** Public URL for a demo host, appending the external port unless it is 80/443. */
    private function url(string $host): string
    {
        $scheme = (string) config('argos.preview.scheme', 'http');
        $port = (int) config('argos.preview.port', 8080);

        $needsPort = ! in_array($port, [80, 443], true);

        return $scheme.'://'.$host.($needsPort ? ':'.$port : '');
    }

    private function routeFilePath(string $slug): string
    {
        return rtrim($this->traefikDir(), '/').'/'.$slug.'.yml';
    }

    private function traefikDir(): string
    {
        return (string) config('argos.preview.traefik_dir', '/data/traefik');
    }
}
