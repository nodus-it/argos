<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\ProviderOAuthConfig;
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

    private const GITLAB_INSTANCE_SESSION_KEY = 'oauth.gitlab.instance';

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

        $account = ConnectedAccount::updateOrCreate(
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

        $account->relinkOrphanedResources();

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

        // Multi-instance: `?instance=<oauthConfigId>` selects a self-hosted
        // GitLab. Without it we use the public-instance config hydrated at boot.
        $instanceUrl = $this->applyGitlabInstance($request->query('instance'));
        $request->session()->put(self::GITLAB_INSTANCE_SESSION_KEY, $instanceUrl);

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('gitlab');

        return $driver->scopes(['read_user', 'api'])->redirect();
    }

    public function callbackGitlab(Request $request): RedirectResponse
    {
        // The callback is a fresh request: re-apply the per-instance OAuth
        // config that the redirect selected, so the token exchange hits the
        // right GitLab instance with the right client credentials.
        $instanceUrl = (string) $request->session()->pull(self::GITLAB_INSTANCE_SESSION_KEY, '');
        $this->applyGitlabConfigForInstance($instanceUrl);

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('gitlab');

        /** @var SocialiteUser $socialUser */
        $socialUser = $driver->user();

        /** @var User $user */
        $user = Auth::user();

        $account = ConnectedAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'gitlab', 'instance_url' => $instanceUrl],
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

        $returnTo = $request->session()->pull(self::GITLAB_RETURN_SESSION_KEY);

        if ($returnTo === 'onboarding') {
            return redirect()->route('filament.admin.pages.onboarding');
        }

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    /**
     * Resolve the chosen GitLab OAuth config (by config id), push its
     * credentials into config('services.gitlab.*'), and return the instance_url
     * to remember ('' for the public instance / unknown id).
     */
    private function applyGitlabInstance(mixed $configId): string
    {
        if (! is_string($configId) || $configId === '') {
            return '';
        }

        $config = ProviderOAuthConfig::query()
            ->where('provider', 'gitlab')
            ->where('enabled', true)
            ->whereKey($configId)
            ->first();

        if (! $config instanceof ProviderOAuthConfig) {
            return '';
        }

        $this->pushGitlabConfig($config->client_id, $config->client_secret, $config->instance_url);

        return $config->instance_url;
    }

    /** Re-apply the gitlab OAuth config for a known instance_url (callback side). */
    private function applyGitlabConfigForInstance(string $instanceUrl): void
    {
        if ($instanceUrl === '') {
            return; // public instance already hydrated at boot
        }

        $config = ProviderOAuthConfig::query()
            ->where('provider', 'gitlab')
            ->where('enabled', true)
            ->where('instance_url', $instanceUrl)
            ->first();

        if ($config instanceof ProviderOAuthConfig) {
            $this->pushGitlabConfig($config->client_id, $config->client_secret, $config->instance_url);
        }
    }

    private function pushGitlabConfig(string $clientId, string $clientSecret, string $instanceUrl): void
    {
        $instance = $instanceUrl !== '' ? $instanceUrl : 'https://gitlab.com';

        config([
            'services.gitlab.client_id' => $clientId,
            'services.gitlab.client_secret' => $clientSecret,
            // socialiteproviders/gitlab concatenates this verbatim — guarantee a trailing slash.
            'services.gitlab.instance_uri' => rtrim($instance, '/').'/',
        ]);
    }

    public function disconnectGitlab(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'gitlab')->delete();

        return redirect()->route('filament.admin.pages.connected-accounts');
    }
}
