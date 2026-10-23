<?php

declare(strict_types=1);

namespace App\Services\Demo;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Owns the Traefik file-provider route for a demo: writing the dynamic-config
 * route file, attaching the workspace forwardAuth middleware, recovering the upstream port from an existing
 * route, and building the public demo URL. Split out of DemoDeployer so routing
 * is one concern; the deployer orchestrates and asks the router for routes/URLs.
 */
class TraefikRouter
{
    /**
     * Write the Traefik file-provider route for this demo into the shared dir
     * and return the public URL. Traefik resolves the `{slug}` host alias on the
     * edge network to the entry container. Demos are never public: every
     * request passes the workspace gate (membership check).
     */
    public function writeRoute(string $slug, int $port): string
    {
        $host = $slug.'.'.config('argos.preview.base_domain', '127.0.0.1.nip.io');

        $router = [
            'rule' => "Host(`{$host}`)",
            'entryPoints' => ['web'],
            'service' => $slug,
            'middlewares' => [$slug.'-auth'],
        ];

        $this->writeRouteFile($slug, [
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
            'middlewares' => [$slug.'-auth' => $this->gateMiddleware()],
        ]);

        return $this->urlForSlug($slug);
    }

    /**
     * @param  array<string, mixed>  $http
     */
    private function writeRouteFile(string $slug, array $http): void
    {
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
    }

    /**
     * Route `code-<slug>.<base>` to the workspace's OpenVSCode Server, guarded
     * by forwardAuth against the workspace gate (membership check — the
     * editor is a shell into the container, it is never public). Behind the
     * gate the route adds the workspace's editor token, which the daemon's
     * editor gate requires — so other workspaces on the Docker network can't
     * reach the editor around Traefik. Returns the editor URL.
     */
    public function writeCodeRoute(string $workspaceSlug, string $editorToken, int $port = 3000): string
    {
        $key = self::codeKey($workspaceSlug);
        $host = $key.'.'.config('argos.preview.base_domain', '127.0.0.1.nip.io');

        $this->writeRouteFile($key, [
            'routers' => [$key => [
                'rule' => "Host(`{$host}`)",
                'entryPoints' => ['web'],
                'service' => $key,
                'middlewares' => [$key.'-auth', $key.'-token'],
            ]],
            'services' => [$key => [
                'loadBalancer' => ['servers' => [['url' => 'http://argos-ws-'.$workspaceSlug.':'.$port]]],
            ]],
            'middlewares' => [
                $key.'-auth' => $this->gateMiddleware(),
                $key.'-token' => ['headers' => ['customRequestHeaders' => ['X-Argos-Editor-Token' => $editorToken]]],
            ],
        ]);

        return $this->urlForSlug($key);
    }

    public function removeCodeRoute(string $workspaceSlug): void
    {
        $this->removeRoute(self::codeKey($workspaceSlug));
    }

    /** Route/host key of a workspace editor. */
    public static function codeKey(string $workspaceSlug): string
    {
        return 'code-'.$workspaceSlug;
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

    /** Public URL for a demo slug (scheme + slug.base_domain + external port). */
    public function urlForSlug(string $slug): string
    {
        return $this->url($slug.'.'.config('argos.preview.base_domain', '127.0.0.1.nip.io'));
    }

    /**
     * forwardAuth against the workspace gate.
     *
     * @return array<string, mixed>
     */
    private function gateMiddleware(): array
    {
        return [
            'forwardAuth' => [
                'address' => (string) config('argos.workspaces.gate_url', 'http://nginx:80/_argos/workspace-gate'),
                // Traefik sets X-Forwarded-Host/-Proto/-Uri from the request it
                // routes; a client's own X-Forwarded-Host must never pick the
                // workspace the gate checks (the route still targets another).
                'trustForwardHeader' => false,
            ],
        ];
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
