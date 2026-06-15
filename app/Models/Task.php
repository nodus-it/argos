<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentName;
use App\Enums\ClaudeModel;
use App\Enums\DemoAccessMode;
use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Presenters\TaskPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $repo_profile_id
 * @property string $description
 * @property string|null $base_branch
 * @property string|null $feature_branch
 * @property string|null $pr_url
 * @property Phase|null $current_phase
 * @property PhaseStatus|null $current_status
 * @property WorkflowStatus $workflow_status
 * @property bool $auto_concept
 * @property int|null $max_turns_concept
 * @property int|null $max_turns_implement
 * @property string|null $model_concept
 * @property string|null $model_implement
 * @property string|null $worker_stack_id_override
 * @property AgentName|null $worker_agent_name_override
 * @property array<string, mixed>|null $worker_config_override
 * @property string|null $agent_credential_id
 * @property array<string, mixed>|null $agent_config
 * @property string|null $concept_md
 * @property string|null $concept_notes
 * @property string|null $implement_summary_nontechnical
 * @property string|null $implement_summary_technical
 * @property string|null $implement_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read RepoProfile|null $repoProfile
 * @property-read Collection<int, PhaseRun> $phaseRuns
 * @property-read ExternalIssueLink|null $externalIssueLink
 * @property-read WorkerStack|null $workerStackOverride
 * @property-read AgentCredential|null $agentCredential
 */
class Task extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'repo_profile_id',
        'description',
        'base_branch',
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
        'max_turns_concept',
        'max_turns_implement',
        'model_concept',
        'model_implement',
        'worker_stack_id_override',
        'worker_agent_name_override',
        'worker_config_override',
        'demo_access_mode',
        'demo_basic_password',
        'agent_credential_id',
        'agent_config',
    ];

    protected function casts(): array
    {
        return [
            'workflow_status' => WorkflowStatus::class,
            'current_phase' => Phase::class,
            'current_status' => PhaseStatus::class,
            'auto_concept' => 'boolean',
            'max_turns_concept' => 'integer',
            'max_turns_implement' => 'integer',
            'worker_agent_name_override' => AgentName::class,
            'worker_config_override' => 'array',
            'demo_access_mode' => DemoAccessMode::class,
            'agent_config' => 'array',
        ];
    }

    /**
     * The effective demo access mode, resolving `Inherit` against the
     * stack-wide default (config argos.preview.auth).
     */
    public function effectiveDemoAccessMode(): DemoAccessMode
    {
        return ($this->demo_access_mode ?? DemoAccessMode::Inherit)->resolve();
    }

    /**
     * Resolves the Claude model ID for a given phase using three-level priority:
     * task-level override → RepoProfile default → hardcoded Argos default.
     */
    public function modelForPhase(string $phase): string
    {
        $taskModel = match ($phase) {
            'concept' => $this->model_concept,
            'implement' => $this->model_implement,
            default => null,
        };

        if ($taskModel !== null) {
            return $taskModel;
        }

        $profile = $this->repoProfile;
        if ($profile !== null) {
            $profileModel = match ($phase) {
                'concept' => $profile->model_concept,
                'implement' => $profile->model_implement,
                default => null,
            };
            if ($profileModel !== null) {
                return $profileModel;
            }
        }

        return ClaudeModel::default($phase)->value;
    }

    public function volumeName(): string
    {
        return 'task_ws_'.self::slugifyName($this->slug);
    }

    public static function slugifyName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) ?? $name;
    }

    /**
     * Turn a free-text task name into a git-/path-safe slug. Mirrors the
     * worker's `_concept_branch_slug` (transliterate umlauts, space/slash → '-',
     * strip the rest, keep case) so that slug == branch suffix == volume key.
     */
    public static function slugifyForBranch(string $value): string
    {
        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ]);
        $value = str_replace([' ', '/'], '-', $value);
        $value = preg_replace('/[^a-zA-Z0-9._-]/', '', $value) ?? '';

        return trim($value, '-_.');
    }

    /**
     * Build a unique, frozen slug from a name (slugify + numeric suffix on
     * collision). Falls back to 'task' when the name slugifies to empty.
     */
    public static function generateSlug(string $name): string
    {
        $base = self::slugifyForBranch($name);
        if ($base === '') {
            $base = 'task';
        }

        $slug = $base;
        $suffix = 2;
        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    protected static function booted(): void
    {
        // The slug is the frozen operational identity (volume/branch/log paths).
        // Auto-derive it from the name on create when not set explicitly; it is
        // never regenerated on rename.
        static::creating(function (Task $task): void {
            if (($task->slug ?? '') === '') {
                $task->slug = self::generateSlug((string) $task->name);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * The external issue this task was imported from, if any.
     *
     * @return HasOne<ExternalIssueLink, $this>
     */
    public function externalIssueLink(): HasOne
    {
        return $this->hasOne(ExternalIssueLink::class);
    }

    /**
     * @return BelongsTo<WorkerStack, $this>
     */
    public function workerStackOverride(): BelongsTo
    {
        return $this->belongsTo(WorkerStack::class, 'worker_stack_id_override');
    }

    /**
     * @return BelongsTo<AgentCredential, $this>
     */
    public function agentCredential(): BelongsTo
    {
        return $this->belongsTo(AgentCredential::class);
    }

    /**
     * Live-demo deployments for this task (latest is the current one).
     *
     * @return HasMany<Demo, $this>
     */
    public function demos(): HasMany
    {
        return $this->hasMany(Demo::class);
    }

    /** The most recent demo for this task, if any. */
    public function currentDemo(): ?Demo
    {
        return $this->demos()->latest()->first();
    }

    /**
     * Returns the started_at timestamp of the most recently started PhaseRun,
     * or null if no PhaseRun has been started yet.
     */
    public function currentPhaseStartedAt(): ?Carbon
    {
        return $this->phaseRuns()
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->latest('started_at')
            ->first()
            ?->started_at;
    }

    /**
     * Whether a phase has hit the turn limit repeatedly (default ≥2 runs ended
     * with error_max_turns). A signal that the agent is not converging — the
     * task is likely too broad or the budget too small — so the UI can nudge
     * the user to narrow the task / raise max-turns instead of blindly
     * resuming again.
     */
    public function hasRepeatedMaxTurns(string $phase, int $threshold = 2): bool
    {
        return $this->phaseRuns()
            ->where('phase', $phase)
            ->where('stop_reason', 'error_max_turns')
            ->count() >= $threshold;
    }

    /** Display-layer derivations (status label/colour, badge, phase rail). */
    public function presenter(): TaskPresenter
    {
        return new TaskPresenter($this);
    }
}
