<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewCommentStatus;
use App\Models\ReviewComment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewComment>
 */
class ReviewCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'path' => 'routes/web.php',
            'side' => 'new',
            'line_start' => 3,
            'line_end' => 3,
            'anchor_hash' => sha1('Route::view(\'/\', \'welcome\');'),
            'snippet' => "Route::view('/', 'welcome');",
            'body' => fake()->sentence(),
            'status' => ReviewCommentStatus::Draft,
        ];
    }
}
