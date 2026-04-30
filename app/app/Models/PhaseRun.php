<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhaseRun extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result_json' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
