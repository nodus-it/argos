<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;

/**
 * Builds the "create a token / OAuth app at the provider" deep links and the
 * required scopes, so the UI can guide setup inline instead of relying on docs.
 * Deep links are pre-filled (name, scopes, callback) wherever the provider
 * supports query parameters on its creation page.
 */
final class ProviderSetupGuide
{
    private const APP_NAME = 'Argos';

    /**
     * Personal Access Token creation guidance.
     *
     * @return array{url: ?string, scopes: string}
     */
    public function pat(IntegrationProvider $provider, ?string $instanceUrl = null): array
    {
        $base = $this->baseHost($provider, $instanceUrl);

        return match ($provider) {
            IntegrationProvider::GitHub => [
                'url' => "{$base}/settings/tokens/new?description=".rawurlencode(self::APP_NAME).'&scopes=repo',
                'scopes' => 'repo',
            ],
            IntegrationProvider::GitLab => [
                'url' => "{$base}/-/user_settings/personal_access_tokens?name=".rawurlencode(self::APP_NAME).'&scopes=api,write_repository',
                'scopes' => 'api, write_repository',
            ],
            IntegrationProvider::Bitbucket => [
                // App passwords have no scope/name query prefill.
                'url' => "{$base}/account/settings/app-passwords/new",
                'scopes' => 'Repositories (read/write), Pull requests, Webhooks',
            ],
            IntegrationProvider::Linear => [
                'url' => 'https://linear.app/settings/api',
                'scopes' => 'read, write',
            ],
        };
    }

    /**
     * OAuth app creation guidance. The GitHub registration page accepts the app
     * name, homepage and callback as query parameters; others only deep-link to
     * the right page (the callback URL is shown separately in the form).
     *
     * @return array{url: ?string, scopes: string}
     */
    public function oauthApp(IntegrationProvider $provider, ?string $instanceUrl, string $homepageUrl, string $callbackUrl): array
    {
        $base = $this->baseHost($provider, $instanceUrl);

        return match ($provider) {
            IntegrationProvider::GitHub => [
                'url' => "{$base}/settings/applications/new?".http_build_query([
                    'oauth_application[name]' => self::APP_NAME,
                    'oauth_application[url]' => $homepageUrl,
                    'oauth_application[callback_url]' => $callbackUrl,
                ]),
                'scopes' => 'repo',
            ],
            IntegrationProvider::GitLab => [
                'url' => "{$base}/-/user_settings/applications",
                'scopes' => 'api, read_user',
            ],
            IntegrationProvider::Bitbucket => [
                // OAuth consumers live under a specific workspace — no generic link.
                'url' => null,
                'scopes' => 'Repositories, Pull requests, Webhooks',
            ],
            IntegrationProvider::Linear => [
                'url' => 'https://linear.app/settings/api/applications/new',
                'scopes' => 'read, write, issues:create, comments:create',
            ],
        };
    }

    /** Provider host without trailing slash; honours a self-hosted instance URL. */
    private function baseHost(IntegrationProvider $provider, ?string $instanceUrl): string
    {
        if (is_string($instanceUrl) && trim($instanceUrl) !== '') {
            return rtrim(trim($instanceUrl), '/');
        }

        return match ($provider) {
            IntegrationProvider::GitHub => 'https://github.com',
            IntegrationProvider::GitLab => 'https://gitlab.com',
            IntegrationProvider::Bitbucket => 'https://bitbucket.org',
            IntegrationProvider::Linear => 'https://linear.app',
        };
    }
}
