<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.
// Cookie domain + secure flag are derived from APP_URL so a single APP_URL is the
// source of truth (no separate SESSION_DOMAIN / SESSION_SECURE_COOKIE needed).

$appUrl = (string) env('APP_URL', 'http://localhost');
$appHost = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

// A leading-dot cookie domain (.example.com) lets the Argos session span demo
// subdomains (demo-<task>.example.com) — required for session-protected demos.
// Only valid for a real registrable domain; bare localhost / IPs / *.nip.io
// (dev) must stay host-only (null) or browsers reject the cookie.
$bareHost = $appHost === 'localhost'
    || filter_var($appHost, FILTER_VALIDATE_IP) !== false
    || str_ends_with($appHost, '.nip.io')
    || ! str_contains($appHost, '.');

return [

    'driver' => 'database',

    'lifetime' => 120,

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => null,

    'table' => 'sessions',

    'store' => null,

    'lottery' => [2, 100],

    // Env-overridable only so Argos-deployed-as-its-own-live-demo can pick a
    // distinct name (.argos/demo.compose.yml sets SESSION_COOKIE). The main app
    // sets a leading-dot `.{domain}` cookie that spans demo-<task>.{domain}; if
    // the demo is also an Argos instance it would otherwise reuse `argos_session`
    // too, the browser would send the main app's cookie to the demo, the demo
    // couldn't decrypt it (different APP_KEY) and would reset the session every
    // request → demo login never persists. Irrelevant for third-party demos
    // (different cookie name) and for the main app (env unset → default below).
    'cookie' => env('SESSION_COOKIE', 'argos_session'),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN') ?: ($bareHost ? null : '.'.$appHost),

    'secure' => env('SESSION_SECURE_COOKIE', str_starts_with($appUrl, 'https://')),

    'http_only' => true,

    'same_site' => 'lax',

    'partitioned' => false,

    'serialization' => 'json',

];
