<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepoProfile extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'url',
        'token',
        'default_branch',
        'platform',
        'worker_image',
        'auto_concept',
        'auto_pr',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'auto_concept' => 'boolean',
            'auto_pr' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
