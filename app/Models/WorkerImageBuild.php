<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentName;
use App\Enums\WorkerImageBuildStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $worker_stack_id
 * @property AgentName $agent_name
 * @property string|null $stack_hash the 8-char hash of stack.dockerfile_body the build was made against
 * @property string $tag
 * @property WorkerImageBuildStatus $status
 * @property string|null $build_log
 * @property Carbon|null $built_at
 * @property int|null $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkerStack $stack
 */
class WorkerImageBuild extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'worker_stack_id',
        'agent_name',
        'stack_hash',
        'tag',
        'status',
        'build_log',
        'built_at',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'agent_name' => AgentName::class,
            'status' => WorkerImageBuildStatus::class,
            'built_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkerStack, $this>
     */
    public function stack(): BelongsTo
    {
        return $this->belongsTo(WorkerStack::class, 'worker_stack_id');
    }

    /**
     * A build is "outdated" when one of two drifts applies:
     *
     * 1. **Stack drift**: The 8-char hash this build was made against is
     *    not the current `sha256(stack.dockerfile_body)[0:8]`. The resolver
     *    would compute a different worker tag now, so this build is
     *    objectively obsolete regardless of what's inside the image.
     *
     * 2. **Agent drift**: The agent has `has_update=true` AND this build
     *    pre-dates the last npm-version check. Fresh builds (built_at >
     *    agent.last_checked_at) are NEVER agent-outdated, because they
     *    just pulled the current upstream during their own `npm install`.
     *
     * Failed builds (no built_at) are never considered outdated — they
     * have nothing to drift from.
     */
    public function isOutdated(): bool
    {
        if ($this->stack !== null && $this->stack_hash !== null) {
            $currentHash = substr(hash('sha256', $this->stack->dockerfile_body), 0, 8);
            if ($this->stack_hash !== $currentHash) {
                return true;
            }
        }

        if ($this->built_at === null) {
            return false;
        }

        $version = AgentVersion::query()->find($this->agent_name->value);
        if ($version?->has_update !== true || $version->last_checked_at === null) {
            return false;
        }

        return $this->built_at->lessThan($version->last_checked_at);
    }

    /**
     * Restrict the query to builds matching the isOutdated() definition,
     * fully expressible in SQL — drives the table filter and the bulk
     * "rebuild all outdated" action so both stay in sync.
     *
     * @param  Builder<WorkerImageBuild>  $query
     * @return Builder<WorkerImageBuild>
     */
    public function scopeOutdated(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            // (1) Stack drift: stack_hash != current sha256(dockerfile_body)[0:8].
            // The hash isn't an SQL function in a portable way, so we
            // pre-compute the (stack_id, current_hash) pairs in PHP and
            // generate one OR-clause per stack. Stacks-table is small
            // (one digit count typical), so this is harmless.
            foreach (WorkerStack::query()->get(['id', 'dockerfile_body']) as $stack) {
                $currentHash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);
                $q->orWhere(fn (Builder $qq) => $qq
                    ->where('worker_stack_id', $stack->id)
                    ->where('stack_hash', '!=', $currentHash));
            }

            // (2) Agent drift: agent has_update + build built before last
            // npm version check. Fresh builds skip this branch because their
            // built_at is past last_checked_at.
            $q->orWhereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('agent_versions')
                    ->whereColumn('agent_versions.agent_name', 'worker_image_builds.agent_name')
                    ->where('agent_versions.has_update', true)
                    ->whereColumn('worker_image_builds.built_at', '<', 'agent_versions.last_checked_at')
                    ->whereNotNull('worker_image_builds.built_at');
            });
        });
    }
}
