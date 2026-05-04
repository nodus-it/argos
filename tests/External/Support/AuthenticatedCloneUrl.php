<?php

declare(strict_types=1);

namespace Tests\External\Support;

/**
 * Builds an HTTPS clone URL with embedded credentials, provider-specific.
 *
 * Note: this intentionally diverges from the worker's `git_auth_inject_token`
 * (which always uses `oauth2:<token>@`) — Bitbucket needs a different shape
 * for both PAT (Basic auth with username:app_password) and OAuth (the magic
 * `x-token-auth` user). The contract suite uses the *correct* shape per
 * provider so the test itself is not held back by an unrelated worker bug.
 */
final class AuthenticatedCloneUrl
{
    /**
     * @param  string  $provider  one of 'github', 'gitlab', 'bitbucket'
     * @param  string  $cloneUrl  plain https://… clone URL
     * @param  string  $authKind  'pat' or 'oauth'
     * @param  string  $token  the token. For Bitbucket: a Repository Access Token
     *                         (no colon — Bearer-style), an Atlassian API Token
     *                         ("email:api_token" — Basic), or a legacy App Password
     *                         ("username:app_password" — Basic, deprecated 2026-06-09).
     */
    public static function build(string $provider, string $cloneUrl, string $authKind, string $token): string
    {
        if (! preg_match('#^(https?://)(.*)$#', $cloneUrl, $m)) {
            return $cloneUrl;
        }

        $scheme = $m[1];
        $rest = $m[2];

        // Bitbucket dispatches on token *shape*, not on $authKind: a token with a colon
        // is "user:secret" form (Basic Auth) and goes verbatim into the URL; a colon-free
        // token is Bearer-style and needs the magic `x-token-auth` user-info prefix.
        // This mirrors the auto-detection in BitbucketGitService.
        $userInfo = match (true) {
            $provider === 'bitbucket' && str_contains($token, ':') => $token,
            $provider === 'bitbucket' => "x-token-auth:{$token}",
            default => "oauth2:{$token}",
        };

        return "{$scheme}{$userInfo}@{$rest}";
    }

    /**
     * Replaces credentials in a URL with `***` for safe logging.
     */
    public static function scrub(string $urlOrLine): string
    {
        return preg_replace('#://[^@/\s]+@#', '://***@', $urlOrLine) ?? $urlOrLine;
    }
}
