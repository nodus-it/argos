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
            'filament.admin.resources.repo-profiles.create',
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
