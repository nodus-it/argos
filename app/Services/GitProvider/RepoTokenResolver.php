<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Enums\GitProvider;
use App\Models\Workspace;

/**
 * The repository token a workspace authenticates git with, and its git
 * credential form. Used by the workspace credential endpoint, which resolves
 * — and refreshes — tokens through it.
 */
class RepoTokenResolver
{
    /** PAT or (refreshed) OAuth token of the project. */
    public function token(Workspace $workspace): string
    {
        return $workspace->resolveToken();
    }

    /**
     * Username/password pair for git's credential protocol. Mirrors
     * `git_auth_header` (the workspace git credential helper): a token that already
     * carries `user:secret` is split, Bitbucket uses `x-token-auth`, every
     * other provider accepts `oauth2`.
     *
     * @return array{username: string, password: string}
     */
    public function gitCredential(Workspace $workspace): array
    {
        $token = $this->token($workspace);

        if (str_contains($token, ':')) {
            [$username, $password] = explode(':', $token, 2);

            return ['username' => $username, 'password' => $password];
        }

        $username = $workspace->platform === GitProvider::Bitbucket ? 'x-token-auth' : 'oauth2';

        return ['username' => $username, 'password' => $token];
    }
}
