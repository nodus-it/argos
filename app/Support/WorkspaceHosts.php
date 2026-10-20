<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether workspace hosts (code-<slug>, demo-ws-<slug>) can be embedded in
 * the Argos page. Browsers only send their access cookie inside an iframe
 * when those hosts are same-site with the app (e.g. app `argos.example.com`
 * and `code-x.argos.example.com`); otherwise (local `127.0.0.1` vs.
 * `*.127.0.0.1.nip.io`) the UI opens them in a new tab instead.
 */
final class WorkspaceHosts
{
    public static function embeddable(): bool
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $base = strtolower((string) config('argos.preview.base_domain', ''));
        if ($appHost === '' || $base === '' || filter_var($appHost, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return $base === $appHost
            || str_ends_with($base, '.'.$appHost)
            || str_ends_with($appHost, '.'.$base);
    }
}
