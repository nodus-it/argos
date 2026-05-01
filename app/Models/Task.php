<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'repo_profile_id',
        'description',
        'feature_branch',
        'pr_url',
        'current_phase',
        'current_status',
    ];

    public function repoProfile(): BelongsTo
    {
        return $this->belongsTo(RepoProfile::class);
    }

    public function phaseRuns(): HasMany
    {
        return $this->hasMany(PhaseRun::class);
    }
}
