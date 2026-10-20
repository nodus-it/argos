<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ThreadEventType;
use App\Models\Thread;
use App\Models\ThreadEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadEvent>
 */
class ThreadEventFactory extends Factory
{
    protected $model = ThreadEvent::class;

    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'type' => ThreadEventType::AssistantMessage,
            'payload' => ['text' => fake()->sentence()],
        ];
    }
}
