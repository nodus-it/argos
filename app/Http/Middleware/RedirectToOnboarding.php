<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\RepoProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(
            'filament.admin.pages.onboarding',
            // The in-app docs (setup/operations) must be reachable before the
            // first RepoProfile exists, so onboarding can link into them.
            'filament.admin.pages.docs',
            'filament.admin.resources.repo-profiles.create',
            // Onboarding links into agent-credentials/create for the Codex
            // setup flow — both create and the post-save edit route must be
            // reachable while no RepoProfile exists yet.
            'filament.admin.resources.agent-credentials.*',
            // OAuth apps and Personal Access Tokens are set up before the first
            // RepoProfile exists, so the onboarding flow can reach them too.
            'filament.admin.resources.provider-oauth-configs.*',
            'filament.admin.resources.provider-credentials.*',
            'filament.admin.auth.logout',
        )) {
            return $next($request);
        }

        if (RepoProfile::query()->exists()) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.onboarding');
    }
}
