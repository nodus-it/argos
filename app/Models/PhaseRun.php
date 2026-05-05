<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $task_id
 * @property Phase $phase
 * @property int $iteration
 * @property PhaseStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $exit_code
 * @property string|null $stop_reason
 * @property array<string, mixed>|null $result_json
 * @property string|null $cost_usd
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $concept_md
 * @property string|null $concept_notes
 * @property string|null $stream_log
 * @property string|null $error_log
 * @property string|null $implement_summary_nontechnical
 * @property string|null $implement_summary_technical
 * @property string|null $implement_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task|null $task
 */
class PhaseRun extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'task_id',
        'phase',
        'iteration',
        'status',
        'started_at',
        'finished_at',
        'exit_code',
        'stop_reason',
        'result_json',
        'cost_usd',
        'input_tokens',
        'output_tokens',
        'concept_md',
        'concept_notes',
        'stream_log',
        'error_log',
        'implement_summary_nontechnical',
        'implement_summary_technical',
        'implement_notes',
    ];

    protected function casts(): array
    {
        return [
            'phase' => Phase::class,
            'status' => PhaseStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result_json' => 'array',
            'cost_usd' => 'decimal:6',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
