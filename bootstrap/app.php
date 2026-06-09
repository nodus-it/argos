<?php

use App\Http\Middleware\ForceJsonForApi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Argos is designed to run behind a reverse proxy (the bundled nginx
        // service plus, typically, a TLS-terminating proxy like HAProxy /
        // Traefik / Caddy in front). Without trusting the forwarded headers
        // Laravel sees plain HTTP, so URL::asset() and request->isSecure()
        // produce http:// URLs and the browser flags mixed content.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // REST API always speaks JSON — so an unauthenticated request answers
        // 401 instead of redirecting (302) to the Filament login.
        $middleware->api(prepend: [ForceJsonForApi::class]);

        // Sanctum ability gates for the REST API (not auto-registered).
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
