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
        return RepoProfile::query()
            ->where('platform', GitProvider::from($this->provider))
            ->where('auth_method', AuthMethod::OAuth)
            ->whereNull('connected_account_id')
            ->update(['connected_account_id' => $this->id]);
    }
}
