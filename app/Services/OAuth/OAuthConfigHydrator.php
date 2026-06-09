<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\ProviderOAuthConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mirrors DB-stored OAuth app credentials into config('services.*') at boot.
 *
 * The DB is the source of truth; ENV (config/services.php) remains the fallback
 * for any provider/instance that has no enabled row. Runs in every context
 * (web + queue) because it hooks AppServiceProvider::boot(), so the queue-side
 * TokenRefresher sees the same credentials. Results are cached for 1h and the
 * cache is invalidated whenever a ProviderOAuthConfig is written.
 */
final class OAuthConfigHydrator
{
    private const CACHE_KEY = 'oauth_configs.hydration';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Apply the public-instance ('') OAuth configs onto config('services.*').
     * Self-hosted instances are resolved on demand (see resolve()), not pushed
     * into the global config. Silently no-ops when the table is unavailable
     * (fresh install, mid-migration) so booting never breaks.
     */
    public function hydrate(): void
    {
        foreach ($this->publicConfigs() as $provider => $config) {
            $clientId = $config['client_id'] ?? '';
            if ($clientId === '') {
                continue;
            }

            config([
                "services.{$provider}.client_id" => $clientId,
                "services.{$provider}.client_secret" => $config['client_secret'] ?? '',
            ]);

            if ($provider === 'gitlab') {
                // socialiteproviders/gitlab concatenates instance_uri verbatim,
                // so the trailing slash must be guaranteed (mirrors config/services.php).
                $instance = $config['instance_url'] !== '' ? $config['instance_url'] : 'https://gitlab.com';
                config(['services.gitlab.instance_uri' => rtrim($instance, '/').'/']);
            }
        }
    }

    /**
     * Resolve the OAuth client credentials for a given provider + instance,
     * preferring the DB row and falling back to config('services.*') (ENV).
     *
     * @return array{client_id: string, client_secret: string}
     */
    public function resolve(string $provider, string $instanceUrl = ''): array
    {
        $config = ProviderOAuthConfig::query()
            ->where('provider', $provider)
            ->where('instance_url', $instanceUrl)
            ->where('enabled', true)
            ->first();

        if ($config !== null && $config->client_id !== '') {
            try {
                return [
                    'client_id' => $config->client_id,
                    'client_secret' => (string) $config->client_secret,
                ];
            } catch (Throwable $e) {
                // client_secret is encrypted; if it was written under a different
                // APP_KEY (rotation/divergence) the decrypt throws. Degrade to the
                // ENV fallback instead of failing the whole request.
                report($e);
            }
        }

        return [
            'client_id' => (string) config("services.{$provider}.client_id"),
            'client_secret' => (string) config("services.{$provider}.client_secret"),
        ];
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The enabled public-instance configs, keyed by provider. Cached for 1h.
     *
     * @return array<string, array{client_id: string, client_secret: string, instance_url: string}>
     */
    private function publicConfigs(): array
    {
        try {
            if (! Schema::hasTable('provider_oauth_configs')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $out = [];

            $configs = ProviderOAuthConfig::query()
                ->where('enabled', true)
                ->where('instance_url', '')
                ->get();

            foreach ($configs as $config) {
                try {
                    $secret = (string) $config->client_secret;
                } catch (Throwable $e) {
                    // A secret encrypted under a different APP_KEY must not brick
                    // every boot (this runs in AppServiceProvider::boot, web +
                    // queue + package:discover). Skip it; the ENV config remains.
                    report($e);

                    continue;
                }

                $out[$config->provider->value] = [
                    'client_id' => $config->client_id,
                    'client_secret' => $secret,
                    'instance_url' => $config->instance_url,
                ];
            }

            return $out;
        });
    }
}
