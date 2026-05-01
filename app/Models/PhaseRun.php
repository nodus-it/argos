<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'result_json',
        'cost_usd',
        'input_tokens',
        'output_tokens',
        'concept_md',
        'concept_notes',
        'stream_log',
        'implement_summary_nontechnical',
        'implement_summary_technical',
        'implement_notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result_json' => 'array',
            'cost_usd' => 'decimal:6',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
