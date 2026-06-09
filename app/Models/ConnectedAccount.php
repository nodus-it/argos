<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\TaskProviderKind;
use Database\Factories\ConnectedAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_id
 * @property string $token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $name
 * @property string|null $nickname
 * @property string|null $avatar
 * @property string|null $instance_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class ConnectedAccount extends Model
{
    /** @use HasFactory<ConnectedAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'token',
        'refresh_token',
        'expires_at',
        'name',
        'nickname',
        'avatar',
        'instance_url',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Pre-multi-instance code (and callers) used null for "public instance";
        // the column is now NOT NULL with '' as that sentinel. Normalize on the
        // way in so every write path stays safe.
        static::saving(function (ConnectedAccount $account): void {
            if ($account->instance_url === null) {
                $account->instance_url = '';
            }
        });
    }

    /**
     * Returns the GitLab instance URL, defaulting to https://gitlab.com for public GitLab.
     */
    public function getInstanceUrl(): string
    {
        // '' is the public-instance sentinel (was null before the multi-instance
        // migration); both map to the public GitLab host.
        return ($this->instance_url === null || $this->instance_url === '')
            ? 'https://gitlab.com'
            : $this->instance_url;
    }

    /**
     * The avatar URL ready for an <img> tag. Providers (notably self-hosted
     * GitLab) can hand back an avatar that an unauthenticated browser request
     * cannot load as-is: a path relative to the instance, or an http URL that
     * an https page blocks as mixed content. Normalize both here so the stored
     * raw value stays untouched but the rendered URL has a chance to load.
     */
    public function displayAvatarUrl(): ?string
    {
        return self::normalizeAvatarUrl($this->avatar, $this->instance_url);
    }

    /**
     * Make a provider avatar URL renderable in the browser:
     *  - a relative path is resolved against the provider instance base URL
     *    (returns null when there is no base to resolve against — unusable);
     *  - an http URL is upgraded to https, but only when the app itself runs
     *    on https, where an http image would otherwise be blocked as mixed
     *    content (on an http app the original is kept so local dev still works).
     */
    public static function normalizeAvatarUrl(?string $avatar, ?string $instanceUrl = null): ?string
    {
        if (! is_string($avatar) || trim($avatar) === '') {
            return null;
        }

        $avatar = trim($avatar);

        if (str_starts_with($avatar, '/')) {
            $base = is_string($instanceUrl) && trim($instanceUrl) !== ''
                ? rtrim(trim($instanceUrl), '/')
                : '';

            if ($base === '') {
                return null;
            }

            $avatar = $base.$avatar;
        }

        if (str_starts_with($avatar, 'http://') && str_starts_with((string) config('app.url'), 'https://')) {
            $avatar = 'https://'.substr($avatar, 7);
        }

        return $avatar;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Heilt verwaiste OAuth-Ressourcen nach einem Re-Connect: sowohl
     * RepoProfiles als auch Task-Provider-Bindings, deren `connected_account_id`
     * durch einen Disconnect (foreign-key `nullOnDelete`) auf NULL fiel.
     * Re-attacht jedes OAuth-RepoProfile gleicher Git-Plattform und jedes
     * TaskProviderBinding gleicher Issue-Provider-Art ohne Account an diesen
     * frisch wiederverbundenen Account.
     *
     * Aufrufer: OAuth-Callbacks nach `updateOrCreate`. Beim ersten Connect ist
     * die Menge leer (kein Schaden), nach Re-Connect werden die Bindungen
     * zurückgesetzt — Poll / Write-back laufen ohne erneutes Seeden wieder.
     *
     * Annahme single-tenant: Weder RepoProfile noch TaskProviderBinding haben
     * eine eigene `user_id`. `unique(user_id, provider)` auf
     * `connected_accounts` garantiert aber genau einen aktiven Account pro
     * Plattform — alle verwaisten (oauth, NULL)-Ressourcen gehörten also
     * zwangsläufig dem gerade gelöschten Account.
     *
     * @return int Anzahl re-attachter RepoProfiles + Bindings
     */
    public function relinkOrphanedResources(): int
    {
        $count = 0;

        $gitProvider = GitProvider::tryFrom($this->provider);
        if ($gitProvider !== null) {
            $count += RepoProfile::query()
                ->where('platform', $gitProvider)
                ->where('auth_method', AuthMethod::OAuth)
                ->whereNull('connected_account_id')
                ->update(['connected_account_id' => $this->id]);
        }

        $issueKind = TaskProviderKind::tryFrom($this->provider);
        if ($issueKind !== null) {
            $count += TaskProviderBinding::query()
                ->where('kind', $issueKind)
                ->whereNull('connected_account_id')
                ->update(['connected_account_id' => $this->id]);
        }

        return $count;
    }
}
