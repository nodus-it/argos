<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A permission request the agent is waiting on. The id is the daemon's
 * approval id, so a decision can be routed back without a mapping table.
 *
 * @property string $id
 * @property string $thread_id
 * @property string|null $tool_use_id
 * @property ApprovalKind $kind
 * @property string $tool_name
 * @property array<string, mixed> $request
 * @property ApprovalStatus $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property array<string, mixed>|null $response
 * @property-read Thread $thread
 * @property-read User|null $resolver
 */
class ThreadApproval extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = ['id', 'thread_id', 'tool_use_id', 'kind', 'tool_name', 'request', 'status', 'resolved_by', 'resolved_at', 'response'];

    protected function casts(): array
    {
        return [
            'kind' => ApprovalKind::class,
            'status' => ApprovalStatus::class,
            'request' => 'array',
            'response' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
