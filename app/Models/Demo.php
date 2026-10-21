<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DemoStatus;
use Database\Factories\DemoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ephemeral live-demo (preview) deployment of a workspace — one current
 * demo per workspace, replaced on the next build.
 *
 * @property string $id
 * @property string $workspace_id
 * @property DemoStatus $status
 * @property string|null $url
 * @property string|null $compose_project
 * @property int|null $port
 * @property Carbon|null $ttl_until
 * @property string|null $build_log
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace|null $workspace
 */
class Demo extends Model
{
    /** @use HasFactory<DemoFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'workspace_id',
        'status',
        'url',
        'compose_project',
        'port',
        'ttl_until',
        'build_log',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemoStatus::class,
            'port' => 'integer',
            'ttl_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** What the demo was built for, if still present. */
    public function target(): ?Workspace
    {
        return $this->workspace;
    }
}
