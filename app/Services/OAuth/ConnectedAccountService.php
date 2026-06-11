<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Persistence for a user's connected OAuth accounts: upsert from an OAuth
 * callback (and relink any resources orphaned by a previous disconnect) and
 * disconnect a provider. Keeps these writes out of the OAuth controllers.
 */
class ConnectedAccountService
{
    /**
     * Upsert the account for (user, provider[, instance]). Pass $instanceUrl as
     * null when the provider has no instance dimension (GitHub/Bitbucket/Linear)
     * and as the instance URL (possibly '') for multi-instance GitLab.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(User $user, string $provider, array $attributes, ?string $instanceUrl = null): ConnectedAccount
    {
        return DB::transaction(function () use ($user, $provider, $attributes, $instanceUrl): ConnectedAccount {
            $identity = ['user_id' => $user->id, 'provider' => $provider];
            if ($instanceUrl !== null) {
                $identity['instance_url'] = $instanceUrl;
            }

            $account = ConnectedAccount::updateOrCreate($identity, $attributes);
            $account->relinkOrphanedResources();

            return $account;
        });
    }

    /**
     * Map a Socialite user to connected-account attributes (GitHub/GitLab/
     * Bitbucket share this shape; Linear builds its own from the GraphQL viewer).
     *
     * @return array<string, mixed>
     */
    public function socialiteAttributes(SocialiteUser $socialUser): array
    {
        return [
            'provider_id' => (string) $socialUser->getId(),
            'token' => (string) $socialUser->token,
            'refresh_token' => $socialUser->refreshToken,
            'expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
            'name' => $socialUser->getName(),
            'nickname' => $socialUser->getNickname(),
            'avatar' => $socialUser->getAvatar(),
        ];
    }

    public function disconnect(User $user, string $provider): void
    {
        $user->connectedAccounts()->where('provider', $provider)->delete();
    }
}
