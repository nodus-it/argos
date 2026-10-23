<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskEvent>
 */
class TaskEventFactory extends Factory
{
    protected $model = TaskEvent::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'type' => TaskEventType::AssistantMessage,
            'payload' => ['text' => fake()->sentence()],
        ];
    }
}
