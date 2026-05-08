<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkerImageEntityStatus;
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
 * @property string $label
 * @property bool $is_builtin
 * @property string $base_image
 * @property string $dockerfile_body
 * @property array<int, string>|null $common_tools
 * @property array<int, string>|null $capabilities
 * @property WorkerImageEntityStatus $status
 * @property string|null $installed_version
 * @property string|null $upstream_version
 * @property bool $has_update
 * @property string|null $last_builtin_hash
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_built_at
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $createdBy
 * @property-read Collection<int, WorkerImageBuild> $imageBuilds
 */
class WorkerStack extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'label',
        'is_builtin',
        'base_image',
        'dockerfile_body',
        'common_tools',
        'capabilities',
        'status',
        'installed_version',
        'upstream_version',
        'has_update',
        'last_builtin_hash',
        'last_checked_at',
        'last_built_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'common_tools' => 'array',
            'capabilities' => 'array',
            'status' => WorkerImageEntityStatus::class,
            'has_update' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_built_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<WorkerImageBuild, $this>
     */
    public function imageBuilds(): HasMany
    {
        return $this->hasMany(WorkerImageBuild::class);
    }
}
