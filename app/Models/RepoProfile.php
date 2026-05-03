<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $url
 * @property string|null $token
 * @property string $default_branch
 * @property string $platform
 * @property string|null $worker_image
 * @property bool $auto_concept
 * @property bool $auto_pr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Task> $tasks
 */
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

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Normalise repo URLs on save: trim whitespace and strip trailing slashes.
     * A trailing "/" survived in the URL otherwise leaks into
     * `https://api.github.com/repos/<owner>/<repo>//pulls` and the GitHub REST
     * API answers HTTP 404.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : rtrim(trim($value), '/'),
        );
    }
}
