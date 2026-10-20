<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Models\Thread;
use App\Models\ThreadApproval;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ThreadApproval>
 */
class ThreadApprovalFactory extends Factory
{
    protected $model = ThreadApproval::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'thread_id' => Thread::factory(),
            'tool_use_id' => 'toolu_'.Str::random(8),
            'kind' => ApprovalKind::Tool,
            'tool_name' => 'Edit',
            'request' => ['input' => ['file_path' => 'app/Foo.php']],
            'status' => ApprovalStatus::Pending,
        ];
    }
}
