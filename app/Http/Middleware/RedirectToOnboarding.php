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
            'filament.app.pages.setup',
            // The in-app docs (setup/operations) must be reachable before the
            // first workspace exists, so onboarding can link into them.
            'filament.app.pages.docs',
            'filament.app.resources.workspaces.create',
            // My account: profile, own Claude subscriptions (agents are set
            // up before the first workspace exists — create and the post-save
            // edit route) and connected accounts.
            'filament.app.account',
            'filament.app.auth.profile',
            'filament.app.account.*',
            // OAuth apps and Personal Access Tokens are set up before the first
            // workspace exists, so the onboarding flow can reach them too.
            'filament.app.system.resources.oauth-apps.*',
            'filament.app.system.resources.tokens.*',
            'filament.app.auth.logout',
        )) {
            return $next($request);
        }

        if (Workspace::query()->exists()) {
            return $next($request);
        }

        // Onboarding is admin-only; other users land on the (empty) dashboard
        // until an administrator has set up the first workspace.
        if (! Gate::allows('administer-argos')) {
            return $next($request);
        }

        return redirect()->route('filament.app.pages.setup');
    }
}
