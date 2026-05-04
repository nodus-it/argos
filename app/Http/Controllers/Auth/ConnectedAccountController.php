<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

final class ConnectedAccountController extends Controller
{
    private const GITHUB_RETURN_SESSION_KEY = 'oauth.github.return';

    private const GITLAB_RETURN_SESSION_KEY = 'oauth.gitlab.return';

    // ── GitHub ────────────────────────────────────────────────────────────────

    public function redirect(Request $request): RedirectResponse
    {
        if ($request->query('return') === 'onboarding') {
            $request->session()->put(self::GITHUB_RETURN_SESSION_KEY, 'onboarding');
        } else {
            $request->session()->forget(self::GITHUB_RETURN_SESSION_KEY);
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('github');

        return $driver->scopes(['repo'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
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

        $returnTo = $request->session()->pull(self::GITHUB_RETURN_SESSION_KEY);

        if ($returnTo === 'onboarding') {
            return redirect()->route('filament.admin.pages.onboarding');
        }

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    public function disconnect(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'github')->delete();

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    // ── GitLab ────────────────────────────────────────────────────────────────

    public function redirectGitlab(Request $request): RedirectResponse
    {
        if ($request->query('return') === 'onboarding') {
            $request->session()->put(self::GITLAB_RETURN_SESSION_KEY, 'onboarding');
        } else {
            $request->session()->forget(self::GITLAB_RETURN_SESSION_KEY);
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('gitlab');

        return $driver->scopes(['read_user', 'api'])->redirect();
    }

    public function callbackGitlab(Request $request): RedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('gitlab');

        /** @var SocialiteUser $socialUser */
        $socialUser = $driver->user();

        /** @var User $user */
        $user = Auth::user();

        $instanceUrl = rtrim((string) config('services.gitlab.instance_uri', 'https://gitlab.com'), '/');

        ConnectedAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'gitlab'],
            [
                'provider_id' => (string) $socialUser->getId(),
                'token' => (string) $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                'name' => $socialUser->getName(),
                'nickname' => $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
                'instance_url' => $instanceUrl === 'https://gitlab.com' ? null : $instanceUrl,
            ]
        );

        $returnTo = $request->session()->pull(self::GITLAB_RETURN_SESSION_KEY);

        if ($returnTo === 'onboarding') {
            return redirect()->route('filament.admin.pages.onboarding');
        }

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    public function disconnectGitlab(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'gitlab')->delete();

        return redirect()->route('filament.admin.pages.connected-accounts');
    }
}
