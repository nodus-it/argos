<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'repo_profile_id',
        'description',
        'feature_branch',
        'pr_url',
        'current_phase',
        'current_status',
        'workflow_status',
        'auto_concept',
    ];

    protected function casts(): array
    {
        return [
            'workflow_status' => WorkflowStatus::class,
            'auto_concept' => 'boolean',
        ];
    }

    public function volumeName(): string
    {
        return 'task_ws_'.self::slugifyName($this->name);
    }

    public static function slugifyName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) ?? $name;
    }

    public function repoProfile(): BelongsTo
    {
        return $this->belongsTo(RepoProfile::class);
    }

    public function phaseRuns(): HasMany
    {
        return $this->hasMany(PhaseRun::class);
    }

    /**
     * Advance workflow_status based on what a completed phase returned.
     * Also auto-dispatches push if the project has auto_pr enabled after implement.
     */
    public function advanceWorkflow(string $phase, string $phaseStatus): void
    {
        $next = WorkflowStatus::afterPhase($phase, $phaseStatus);

        if ($phase === 'implement' && $phaseStatus === 'completed') {
            if ($this->repoProfile?->auto_pr) {
                RunPhaseJob::dispatch($this->id, 'push');

                // workflow_status stays implement_running until push finishes
                return;
            }

            // No auto_pr: stay in implement_running so the UI shows "Push & PR erstellen"
            return;
        }

        if ($next !== null) {
            $this->update(['workflow_status' => $next]);
        }
    }
}
