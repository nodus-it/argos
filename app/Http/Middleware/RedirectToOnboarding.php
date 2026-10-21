<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class RedirectToOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(
            'filament.admin.pages.onboarding',
            // The in-app docs (setup/operations) must be reachable before the
            // first workspace exists, so onboarding can link into them.
            'filament.admin.pages.docs',
            'filament.admin.resources.workspaces.create',
            // Agents are set up before the first workspace exists — both
            // create and the post-save edit route must be reachable.
            'filament.admin.resources.agent-credentials.*',
            // OAuth apps and Personal Access Tokens are set up before the first
            // workspace exists, so the onboarding flow can reach them too.
            'filament.admin.resources.provider-oauth-configs.*',
            'filament.admin.resources.provider-credentials.*',
            'filament.admin.auth.logout',
            'filament.workspaces.auth.logout',
        )) {
            return $next($request);
        }

        if (Workspace::query()->exists()) {
            return $next($request);
        }

        // Onboarding is admin-only; other users land on the (empty) dashboard
        // until an administrator has set up the first project.
        if (! Gate::allows('administer-argos')) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.onboarding');
    }
}
