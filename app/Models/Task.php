<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
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
 * @property string|null $repo_profile_id
 * @property string $description
 * @property string|null $feature_branch
 * @property string|null $pr_url
 * @property string|null $current_phase
 * @property string|null $current_status
 * @property WorkflowStatus $workflow_status
 * @property bool $auto_concept
 * @property int|null $max_turns
 * @property string|null $concept_md
 * @property string|null $concept_notes
 * @property string|null $implement_summary_nontechnical
 * @property string|null $implement_summary_technical
 * @property string|null $implement_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RepoProfile|null $repoProfile
 * @property-read Collection<int, PhaseRun> $phaseRuns
 */
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
        'concept_md',
        'concept_notes',
        'implement_summary_nontechnical',
        'implement_summary_technical',
        'implement_notes',
        'current_phase',
        'current_status',
        'workflow_status',
        'auto_concept',
        'max_turns',
    ];

    protected function casts(): array
    {
        return [
            'workflow_status' => WorkflowStatus::class,
            'auto_concept' => 'boolean',
            'max_turns' => 'integer',
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

    /**
     * @return BelongsTo<RepoProfile, $this>
     */
    public function repoProfile(): BelongsTo
    {
        return $this->belongsTo(RepoProfile::class);
    }

    /**
     * @return HasMany<PhaseRun, $this>
     */
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
