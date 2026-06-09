<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Enums\ProviderCredentialStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, reusable Personal Access Token for an integration provider. The
 * token-source counterpart to ConnectedAccount (OAuth): both feed the raw
 * token string into IssueTrackerRegistry / GitServiceFactory, but a PAT needs
 * no refresh and can be created without an OAuth app.
 *
 * @property string $id
 * @property string $label
 * @property IntegrationProvider $provider
 * @property string|null $instance_url
 * @property string $token
 * @property string|null $scopes_hint
 * @property ProviderCredentialStatus $status
 * @property Carbon|null $last_validated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TaskProviderBinding> $taskProviderBindings
 */
class ProviderCredential extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'label',
        'provider',
        'instance_url',
        'token',
        'scopes_hint',
        'status',
        'last_validated_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'token' => 'encrypted',
            'status' => ProviderCredentialStatus::class,
            'last_validated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TaskProviderBinding, $this>
     */
    public function taskProviderBindings(): HasMany
    {
        return $this->hasMany(TaskProviderBinding::class);
    }

    /** The effective instance URL, defaulting to the provider's public SaaS host. */
    public function getInstanceUrl(): string
    {
        if ($this->instance_url !== null && $this->instance_url !== '') {
            return $this->instance_url;
        }

        return match ($this->provider) {
            IntegrationProvider::GitHub => 'https://github.com',
            IntegrationProvider::GitLab => 'https://gitlab.com',
            IntegrationProvider::Bitbucket => 'https://bitbucket.org',
            IntegrationProvider::Linear => 'https://linear.app',
        };
    }
}
