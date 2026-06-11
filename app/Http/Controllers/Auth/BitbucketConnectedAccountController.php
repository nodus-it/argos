<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ReverifiesConnectedAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OAuth\ConnectedAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

final class BitbucketConnectedAccountController extends Controller
{
    use ReverifiesConnectedAccount;

    private const RETURN_SESSION_KEY = 'oauth.bitbucket.return';

    public function redirect(Request $request): RedirectResponse
    {
        if ($request->query('return') === 'onboarding') {
            $request->session()->put(self::RETURN_SESSION_KEY, 'onboarding');
        } else {
            $request->session()->forget(self::RETURN_SESSION_KEY);
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('bitbucket');

        return $driver->scopes(['account', 'repository', 'pullrequest', 'issue'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('bitbucket');

        /** @var SocialiteUser $socialUser */
        $socialUser = $driver->user();

        /** @var User $user */
        $user = Auth::user();

        $service = app(ConnectedAccountService::class);
        $account = $service->upsert($user, 'bitbucket', $service->socialiteAttributes($socialUser));
        $this->reverifyConnectedAccount($account);

        $returnTo = $request->session()->pull(self::RETURN_SESSION_KEY);

        if ($returnTo === 'onboarding') {
            return redirect()->route('filament.admin.pages.onboarding');
        }

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    public function disconnect(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        app(ConnectedAccountService::class)->disconnect($user, 'bitbucket');

        return redirect()->route('filament.admin.pages.connected-accounts');
    }
}
