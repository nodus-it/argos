<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateWorkspaceDaemon;
use App\Models\Workspace;
use App\Services\GitProvider\RepoTokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Hands the workspace daemon the git credential of its project. The daemon
 * keeps it in memory only and serves it to git via the credential helper;
 * tokens are resolved (and OAuth-refreshed) on every call.
 */
class RepoCredentialController extends Controller
{
    public function __invoke(Request $request, RepoTokenResolver $tokens): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get(AuthenticateWorkspaceDaemon::ATTRIBUTE);

        try {
            return response()->json($tokens->gitCredential($workspace));
        } catch (Throwable $e) {
            return response()->json(['error' => ['code' => 'credential_unavailable', 'message' => $e->getMessage()]], 409);
        }
    }
}
