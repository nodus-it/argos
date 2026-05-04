<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $url
 * @property string|null $token
 * @property string $default_branch
 * @property string $platform
 * @property string $auth_method
 * @property int|null $connected_account_id
 * @property string|null $worker_image
 * @property bool $auto_concept
 * @property bool $auto_pr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Task> $tasks
 * @property-read ConnectedAccount|null $connectedAccount
 */
class RepoProfile extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'url',
        'token',
        'default_branch',
        'platform',
        'auth_method',
        'connected_account_id',
        'worker_image',
        'auto_concept',
        'auto_pr',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'auto_concept' => 'boolean',
            'auto_pr' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /**
     * Resolve the repository token for this profile.
     * Returns the PAT when auth_method is 'pat', or the OAuth token from the
     * linked ConnectedAccount when auth_method is 'oauth'.
     *
     * @throws \RuntimeException when no token is configured
     */
    public function resolveToken(): string
    {
        if ($this->auth_method !== 'oauth') {
            if ($this->token !== null && $this->token !== '') {
                return $this->token;
            }

            throw new \RuntimeException(
                'Kein PAT konfiguriert. Bitte Token im Projekt hinterlegen oder GitHub-Account verknüpfen.'
            );
        }

        $account = $this->connectedAccount;
        if ($account === null) {
            throw new \RuntimeException(
                'Kein OAuth-Account verknüpft. Bitte GitHub-Account verbinden.'
            );
        }

        return $account->token;
    }

    /**
     * Extracts the `owner/repo` path from the stored URL, stripping the scheme,
     * host, leading slash, and any `.git` suffix. Works for GitHub, GitLab
     * (including subgroups), and Bitbucket.
     */
    public function getOwnerRepo(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH) ?? '';
        $path = rtrim($path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return ltrim($path, '/');
    }

    /**
     * Normalise repo URLs on save: trim whitespace and strip trailing slashes.
     * A trailing "/" survived in the URL otherwise leaks into
     * `https://api.github.com/repos/<owner>/<repo>//pulls` and the GitHub REST
     * API answers HTTP 404.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : rtrim(trim($value), '/'),
        );
    }
}
