<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepoProfile extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'url',
        'token',
        'default_branch',
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
