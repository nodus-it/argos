<?php

declare(strict_types=1);

use App\Mcp\Servers\ArgosServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// OAuth 2.1 discovery + dynamic client registration (RFC 8414 / 8252) —
// open by design, so throttled per address.
Route::middleware('throttle:oauth-registration')->group(fn () => Mcp::oauthRoutes());

// The Argos MCP server. Authenticated by Passport's `api` guard and gated to
// access tokens that carry the `mcp:use` scope.
Mcp::web('/mcp', ArgosServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'throttle:api']);
