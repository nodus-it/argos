<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an OAuth connection goes back to: the setup wizard when it started
 * there (`?return=onboarding`), else My account → Connected accounts.
 */
trait ReturnsAfterConnect
{
    /** Remember (or forget) where to return, before leaving for the provider. */
    protected function rememberReturn(Request $request, string $provider): void
    {
        if ($request->query('return') === 'onboarding') {
            $request->session()->put($this->returnKey($provider), 'onboarding');
        } else {
            $request->session()->forget($this->returnKey($provider));
        }
    }

    protected function redirectAfterConnect(Request $request, string $provider): RedirectResponse
    {
        return $request->session()->pull($this->returnKey($provider)) === 'onboarding'
            ? redirect()->route('filament.app.pages.setup')
            : redirect()->route('filament.app.account.pages.connections');
    }

    private function returnKey(string $provider): string
    {
        return "oauth.{$provider}.return";
    }
}
