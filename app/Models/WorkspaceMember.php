<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property int|null $added_by
 * @property-read Workspace $workspace
 * @property-read User $user
 */
class WorkspaceMember extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'user_id', 'role', 'added_by'];

    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
