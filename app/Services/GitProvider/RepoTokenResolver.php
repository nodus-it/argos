<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Enums\GitProvider;
use App\Models\RepoProfile;

/**
 * The repository token a worker or workspace authenticates git with, and its
 * git credential form. Shared by PhaseCommandBuilder (tasks) and the
 * workspace credential endpoint so both resolve — and refresh — tokens the
 * same way.
 */
class RepoTokenResolver
{
    /** PAT or (refreshed) OAuth token of the project. */
    public function token(RepoProfile $profile): string
    {
        return $profile->resolveToken();
    }

    /**
     * Username/password pair for git's credential protocol. Mirrors
     * `git_auth_header` (worker/lib/credentials.sh): a token that already
     * carries `user:secret` is split, Bitbucket uses `x-token-auth`, every
     * other provider accepts `oauth2`.
     *
     * @return array{username: string, password: string}
     */
    public function gitCredential(RepoProfile $profile): array
    {
        $token = $this->token($profile);

        if (str_contains($token, ':')) {
            [$username, $password] = explode(':', $token, 2);

            return ['username' => $username, 'password' => $password];
        }

        $username = $profile->platform === GitProvider::Bitbucket ? 'x-token-auth' : 'oauth2';

        return ['username' => $username, 'password' => $token];
    }
}
