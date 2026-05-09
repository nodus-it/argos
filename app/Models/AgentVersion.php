<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cache row tracking installed vs. upstream version for one agent.
 *
 * @property AgentName $agent_name
 * @property string|null $installed_version
 * @property string|null $upstream_version
 * @property bool $has_update
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AgentVersion extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = 'agent_name';

    protected $keyType = 'string';

    protected $fillable = [
        'agent_name',
        'installed_version',
        'upstream_version',
        'has_update',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_name' => AgentName::class,
            'has_update' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }
}
