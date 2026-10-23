<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApiClientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * A named consumer of the REST API with cross-project ("full") access. Sanctum
 * tokens bind here when the access should span all projects — kept separate
 * from User so it never clashes with Passport's HasApiTokens (which powers the
 * MCP server and reserves the same $accessToken property on User).
 *
 * @property string $id
 * @property string $name
 * @property int|null $run_as_user_id
 * @property-read User|null $runAsUser
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiClient extends Model implements HasApiTokensContract
{
    use HasApiTokens;

    /** @use HasFactory<ApiClientFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'run_as_user_id',
        'name',
    ];

    /** The person auto-tasks started with this client's tokens act for. */
    public function runAsUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_as_user_id');
    }
}
