<?php

declare(strict_types=1);

namespace Tests\External\Support;

/**
 * Builds an HTTPS clone URL with embedded credentials, provider-specific.
 *
 * Bitbucket dispatches on token shape (colon present? Basic via "user:secret",
 * else Bearer via the magic `x-token-auth` user-info prefix), mirroring
 * BitbucketGitService. GitHub and GitLab take any bearer-style token via
 * the well-known `oauth2:<token>@` form.
 */
final class AuthenticatedCloneUrl
{
    /**
     * @param  string  $provider  one of 'github', 'gitlab', 'bitbucket'
     * @param  string  $cloneUrl  plain https://… clone URL
     * @param  string  $token  the token. For Bitbucket: a Repository Access Token
     *                         or OAuth token (no colon — Bearer-style), an
     *                         Atlassian API Token ("email:api_token" — Basic),
     *                         or a legacy App Password ("username:app_password" —
     *                         Basic, deprecated 2026-06-09).
     */
    public static function build(string $provider, string $cloneUrl, string $token): string
    {
        if (! preg_match('#^(https?://)(.*)$#', $cloneUrl, $m)) {
            return $cloneUrl;
        }

        $scheme = $m[1];
        $rest = $m[2];

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
