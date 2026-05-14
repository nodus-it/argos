<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExternalIssueLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $task_provider_binding_id
 * @property string|null $task_id
 * @property string $external_id
 * @property string $external_url
 * @property Carbon|null $last_synced_at
 * @property string|null $signature
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TaskProviderBinding $binding
 * @property-read Task|null $task
 */
class ExternalIssueLink extends Model
{
    /** @use HasFactory<ExternalIssueLinkFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'task_provider_binding_id',
        'task_id',
        'external_id',
        'external_url',
        'last_synced_at',
        'signature',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TaskProviderBinding, $this>
     */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(TaskProviderBinding::class, 'task_provider_binding_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
