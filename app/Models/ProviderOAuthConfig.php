<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Services\OAuth\OAuthConfigHydrator;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A DB-stored OAuth application (client_id + client_secret) for an integration
 * provider, optionally scoped to a self-hosted instance. The OAuthConfigHydrator
 * mirrors enabled rows into config('services.*') at boot, making the DB the
 * source of truth with ENV as fallback.
 *
 * @property string $id
 * @property IntegrationProvider $provider
 * @property string $instance_url '' for the public SaaS instance
 * @property string $client_id
 * @property string $client_secret
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProviderOAuthConfig extends Model
{
    use HasFactory;
    use HasUlids;

    // Eloquent would derive "provider_o_auth_configs" from the class name.
    protected $table = 'provider_oauth_configs';

    protected $fillable = [
        'provider',
        'instance_url',
        'client_id',
        'client_secret',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'client_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Normalize the instance URL: an empty form field arrives as null →
        // '' (the public-instance sentinel; keeps the unique index comparable),
        // and any trailing slash is trimmed so it matches the value stored on
        // connected_accounts during the OAuth callback (e.g. ".../" vs "...").
        static::saving(function (ProviderOAuthConfig $config): void {
            $config->instance_url = rtrim(trim((string) $config->instance_url), '/');
        });

        // Keep the hydrator's 1h cache honest: any write invalidates it so the
        // next boot reflects the change.
        static::saved(fn () => app(OAuthConfigHydrator::class)->forgetCache());
        static::deleted(fn () => app(OAuthConfigHydrator::class)->forgetCache());
    }

    /** Whether this row targets the provider's public SaaS instance. */
    public function isPublicInstance(): bool
    {
        return $this->instance_url === '';
    }
}
