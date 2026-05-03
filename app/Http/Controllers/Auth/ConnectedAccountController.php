<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

final class ConnectedAccountController extends Controller
{
    public function redirect(): RedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('github');

        return $driver->scopes(['repo'])->redirect();
    }

    public function callback(): RedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('github');

        /** @var SocialiteUser $socialUser */
        $socialUser = $driver->user();

        /** @var User $user */
        $user = Auth::user();

        ConnectedAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'github'],
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

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    public function disconnect(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'github')->delete();

        return redirect()->route('filament.admin.pages.connected-accounts');
    }
}
