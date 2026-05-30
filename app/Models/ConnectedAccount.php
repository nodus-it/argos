<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
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

    /**
     * Returns the GitLab instance URL, defaulting to https://gitlab.com for public GitLab.
     */
    public function getInstanceUrl(): string
    {
        return $this->instance_url ?? 'https://gitlab.com';
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
     * Heilt verwaiste OAuth-RepoProfiles, deren `connected_account_id` durch
     * einen Disconnect (foreign-key `nullOnDelete`) auf NULL fiel: re-attacht
     * jedes Profil mit gleicher Plattform und `auth_method=oauth` und ohne
     * Account an diesen frisch wiederverbundenen Account.
     *
     * Aufrufer: OAuth-Callbacks nach `updateOrCreate`. Beim ersten Connect ist
     * die Menge leer (kein Schaden), nach Re-Connect werden die Bindungen
     * zurückgesetzt — Tasks gegen alte Profile laufen direkt wieder.
     *
     * Annahme single-tenant: RepoProfile hat keine eigene `user_id`, daher
     * lässt sich nicht weiter auf den Owner einschränken. `unique(user_id,
     * provider)` auf `connected_accounts` garantiert aber, dass es pro Plattform
     * nur einen aktiven Account gibt — alle Profile mit (platform=X, oauth,
     * NULL) gehörten also zwangsläufig genau dem Account, der gerade gelöscht
     * wurde.
     *
     * @return int Anzahl re-attachter Profile (für Logging/Telemetrie)
     */
    public function relinkOrphanedRepoProfiles(): int
    {
        $gitProvider = GitProvider::tryFrom($this->provider);

        if ($gitProvider === null) {
            return 0;
        }

        return RepoProfile::query()
            ->where('platform', $gitProvider)
            ->where('auth_method', AuthMethod::OAuth)
            ->whereNull('connected_account_id')
            ->update(['connected_account_id' => $this->id]);
    }
}
