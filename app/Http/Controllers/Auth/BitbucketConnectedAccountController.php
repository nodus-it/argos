<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ReverifiesConnectedAccount;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\User;
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

        $account = ConnectedAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'bitbucket'],
            [
                'provider_id' => (string) $socialUser->getId(),
                'token' => (string) $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                'name' => $socialUser->getName(),
                'nickname' => $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
            ]
        );

        $account->relinkOrphanedResources();
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

        $user->connectedAccounts()->where('provider', 'bitbucket')->delete();

        return redirect()->route('filament.admin.pages.connected-accounts');
    }
}
