<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConnectedAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_id
 * @property string $token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $name
 * @property string|null $nickname
 * @property string|null $avatar
 * @property string|null $instance_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class ConnectedAccount extends Model
{
    /** @use HasFactory<ConnectedAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'token',
        'refresh_token',
        'expires_at',
        'name',
        'nickname',
        'avatar',
        'instance_url',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Returns the GitLab instance URL, defaulting to https://gitlab.com for public GitLab.
     */
    public function getInstanceUrl(): string
    {
        return $this->instance_url ?? 'https://gitlab.com';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
