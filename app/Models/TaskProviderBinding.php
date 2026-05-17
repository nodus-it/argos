<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use Database\Factories\TaskProviderBindingFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $repo_profile_id
 * @property TaskProviderKind $kind
 * @property TaskProviderMode $mode
 * @property int|null $connected_account_id
 * @property string|null $external_project_ref
 * @property array<string, mixed>|null $filters
 * @property string|null $webhook_id
 * @property string|null $webhook_secret
 * @property Carbon|null $last_polled_at
 * @property string|null $last_error
 * @property TaskProviderSyncStatus $sync_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RepoProfile $repoProfile
 * @property-read ConnectedAccount|null $connectedAccount
 * @property-read Collection<int, ExternalIssueLink> $externalIssueLinks
 */
class TaskProviderBinding extends Model
{
    /** @use HasFactory<TaskProviderBindingFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'repo_profile_id',
        'kind',
        'mode',
        'connected_account_id',
        'external_project_ref',
        'filters',
        'webhook_id',
        'webhook_secret',
        'last_polled_at',
        'last_error',
        'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TaskProviderKind::class,
            'mode' => TaskProviderMode::class,
            'sync_status' => TaskProviderSyncStatus::class,
            'filters' => 'array',
            'webhook_secret' => 'encrypted',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RepoProfile, $this>
     */
    public function repoProfile(): BelongsTo
    {
        return $this->belongsTo(RepoProfile::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /**
     * @return HasMany<ExternalIssueLink, $this>
     */
    public function externalIssueLinks(): HasMany
    {
        return $this->hasMany(ExternalIssueLink::class);
    }
}
