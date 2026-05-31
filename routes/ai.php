<?php

declare(strict_types=1);

use App\Mcp\Servers\ArgosServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// OAuth 2.1 discovery + dynamic client registration (RFC 8414 / 8252).
Mcp::oauthRoutes();

// The Argos MCP server. Authenticated by Passport's `api` guard and gated to
// access tokens that carry the `mcp:use` scope.
Mcp::web('/mcp', ArgosServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use')]);
