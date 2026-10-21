<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $task_id
 * @property int $user_id
 * @property int $last_read_event_id
 */
class TaskRead extends Model
{
    protected $fillable = ['task_id', 'user_id', 'last_read_event_id'];

    protected function casts(): array
    {
        return ['last_read_event_id' => 'integer'];
    }
}
