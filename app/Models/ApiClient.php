<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApiClientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'name',
    ];
}
