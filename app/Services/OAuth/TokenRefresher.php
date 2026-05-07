<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Refreshes short-lived OAuth access tokens against the provider's token
 * endpoint before they expire. Bitbucket (~2h) and GitLab (~2h) issue tokens
 * that can lapse during a long-running worker job; GitHub OAuth Apps with
 * "Expire user authorization tokens" enabled fall in the same bucket.
 *
 * The refresher reads `refresh_token` from the `ConnectedAccount`, hits the
 * provider's `grant_type=refresh_token` flow, persists the rotated tokens
 * back, and returns the same model with `token` updated. A short Cache lock
 * prevents two parallel jobs sharing the same account from racing on a
 * single-use refresh token.
 */
class TokenRefresher
{
    private const ENDPOINT_GITHUB = 'https://github.com/login/oauth/access_token';

    private const ENDPOINT_BITBUCKET = 'https://bitbucket.org/site/oauth2/access_token';

    /**
     * Buffer before `expires_at` at which a token is considered in need of
     * refresh. Sized to cover the full RunPhaseJob timeout (3600s) so a token
     * obtained at dispatch survives the worker's entire run.
     */
    public const REFRESH_BUFFER_SECONDS = 3600;

    public function refreshIfNeeded(
        ConnectedAccount $account,
        int $bufferSeconds = self::REFRESH_BUFFER_SECONDS,
    ): ConnectedAccount {
        if (! $this->needsRefresh($account, $bufferSeconds)) {
            return $account;
        }

        if ($account->refresh_token === null || $account->refresh_token === '') {
            throw new RuntimeException(sprintf(
                'OAuth-Token für %s ist abgelaufen und es liegt kein refresh_token vor — bitte Account neu verbinden.',
                $account->provider,
            ));
        }

        return Cache::lock("oauth.refresh.{$account->id}", 30)->block(
            30,
            function () use ($account, $bufferSeconds): ConnectedAccount {
                // Re-read inside the lock — a sibling job may have refreshed
                // already, in which case we just return what's in the DB and
                // skip a second hit on the provider's endpoint.
                $account->refresh();
                if (! $this->needsRefresh($account, $bufferSeconds)) {
                    return $account;
                }

                return $this->doRefresh($account);
            },
        );
    }

    public function needsRefresh(
        ConnectedAccount $account,
        int $bufferSeconds = self::REFRESH_BUFFER_SECONDS,
    ): bool {
        if ($account->expires_at === null) {
            return false;
        }

        return $account->expires_at->isBefore(now()->addSeconds($bufferSeconds));
    }

    private function doRefresh(ConnectedAccount $account): ConnectedAccount
    {
        [$endpoint, $clientId, $clientSecret] = $this->endpointAndCredentials($account);

        $response = Http::asForm()
            ->acceptJson()
            ->post($endpoint, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if ($response->failed()) {
            // Body may carry an `error_description` from the provider but never
            // the refresh_token; logging the status alone keeps secrets out of
            // the log even if argos-debug is on.
            Log::channel('argos')->warning('OAuth refresh failed', [
                'provider' => $account->provider,
                'account_id' => $account->id,
                'status' => $response->status(),
            ]);
            throw new RuntimeException(sprintf(
                'OAuth-Token-Refresh für %s fehlgeschlagen (HTTP %d) — bitte Account neu verbinden.',
                $account->provider,
                $response->status(),
            ));
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['access_token']) || ! is_string($body['access_token'])) {
            throw new RuntimeException(sprintf(
                'OAuth-Token-Refresh für %s lieferte kein access_token — bitte Account neu verbinden.',
                $account->provider,
            ));
        }

        // Some providers (Bitbucket, GitLab) rotate the refresh_token on every
        // refresh; others may omit it from the response and expect the caller
        // to keep the existing one. Persist whichever the response carries.
        $newRefresh = isset($body['refresh_token']) && is_string($body['refresh_token']) && $body['refresh_token'] !== ''
            ? $body['refresh_token']
            : $account->refresh_token;

        $newExpiresAt = isset($body['expires_in']) && is_numeric($body['expires_in'])
            ? now()->addSeconds((int) $body['expires_in'])
            : null;

        $account->forceFill([
            'token' => $body['access_token'],
            'refresh_token' => $newRefresh,
            'expires_at' => $newExpiresAt,
        ])->save();

        return $account;
    }

    /**
     * @return array{0: string, 1: string, 2: string} endpoint, client_id, client_secret
     */
    private function endpointAndCredentials(ConnectedAccount $account): array
    {
        return match ($account->provider) {
            'github' => [
                self::ENDPOINT_GITHUB,
                (string) config('services.github.client_id'),
                (string) config('services.github.client_secret'),
            ],
            'gitlab' => [
                rtrim($account->getInstanceUrl(), '/').'/oauth/token',
                (string) config('services.gitlab.client_id'),
                (string) config('services.gitlab.client_secret'),
            ],
            'bitbucket' => [
                self::ENDPOINT_BITBUCKET,
                (string) config('services.bitbucket.client_id'),
                (string) config('services.bitbucket.client_secret'),
            ],
            default => throw new RuntimeException(sprintf(
                'Unbekannter OAuth-Provider für Token-Refresh: %s',
                $account->provider,
            )),
        };
    }
}
